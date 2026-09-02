<?php

namespace AsimAli\Pinpoint\Internal;

use AsimAli\Pinpoint\TierClassifier;
use Illuminate\Support\Facades\DB;

/**
 * @internal Not part of Pinpoint's public API contract.
 */
class SummaryReader
{
    public function __construct(protected TierClassifier $tiers) {}

    /**
     * Per-route summary computed on-demand from raw requests.
     *
     * Single pass over the requests table: group durations by route label in
     * PHP instead of re-querying per route (the previous version was an
     * N+1-shaped scan inside the very tool that detects N+1s).
     *
     * @param  int|null  $sinceMinutes  only consider requests from the last N minutes
     * @return array<int, array{route: string, p50: int, p95: int, p99: int, avg: int, samples: int, tier: string, n1_repeat: int, peak_memory_kb: int|null, has_duplicate_queries: bool}>
     */
    public function fromRaw(?int $sinceMinutes = null): array
    {
        $counts = $this->maxRepeatCounts($sinceMinutes);
        $duplicates = $this->routesWithDuplicateQueries($sinceMinutes);

        $durationsByLabel = [];
        $peakMemoryByLabel = [];

        $query = DB::table('pinpoint_requests')->orderBy('id');

        if ($sinceMinutes !== null) {
            $query->where('created_at', '>=', now()->subMinutes($sinceMinutes));
        }

        // chunkById keeps memory bounded for large datasets (cursor() would
        // hold open a connection and skip late rows under concurrent writes).
        $query->chunkById(1000, function ($rows) use (&$durationsByLabel, &$peakMemoryByLabel) {
            foreach ($rows as $row) {
                $label = $row->route_name ?? sprintf('%s %s', $row->method, $row->path);
                $durationsByLabel[$label][] = (int) $row->duration_ms;

                // peak_memory_kb is nullable (pre-migration rows, queue jobs).
                // Track the max peak across all samples for the route.
                $mem = $row->peak_memory_kb ?? null;

                if ($mem !== null) {
                    $current = $peakMemoryByLabel[$label] ?? null;
                    $peakMemoryByLabel[$label] = $current === null ? $mem : max($current, $mem);
                }
            }
        });

        if ($durationsByLabel === []) {
            return [];
        }

        $summaries = [];

        foreach ($durationsByLabel as $label => $durations) {
            $summaries[] = [
                'route' => $label,
                'p50' => Statistics::percentile($durations, 50),
                'p95' => Statistics::percentile($durations, 95),
                'p99' => Statistics::percentile($durations, 99),
                'avg' => (int) round(array_sum($durations) / count($durations)),
                'samples' => count($durations),
                'tier' => $this->tiers->classify(Statistics::percentile($durations, 95), $label),
                'n1_repeat' => $counts[$label] ?? 0,
                // Peak across all recorded requests for this route. Null means
                // no requests for this route had memory data (pre-migration data
                // or routes recorded before the feature was deployed).
                'peak_memory_kb' => $peakMemoryByLabel[$label] ?? null,
                // True when at least one request for this route had a repeated
                // query group classified as 'duplicate' (Cache::remember() fix).
                'has_duplicate_queries' => in_array($label, $duplicates, true),
            ];
        }

        usort($summaries, fn ($a, $b) => $b['p95'] <=> $a['p95']);

        return $summaries;
    }

    protected function maxRepeatCounts(?int $sinceMinutes = null): array
    {
        $perRequest = DB::table('pinpoint_queries')
            ->select('request_id', 'sql_fingerprint')
            ->selectRaw('COUNT(*) as repeat_count')
            ->selectRaw('COUNT(DISTINCT bindings_hash) as distinct_hashes')
            ->selectRaw('SUM(CASE WHEN bindings_hash IS NULL THEN 1 ELSE 0 END) as null_count')
            ->groupBy('request_id', 'sql_fingerprint');

        $query = DB::table('pinpoint_requests as r')
            ->joinSub($perRequest, 'rc', 'rc.request_id', '=', 'r.id')
            // Exact duplicates (same fingerprint AND same non-null bindings
            // across all rows) are cache candidates, not N+1 — keep them out
            // of the repeat count so the report doesn't label them N+1.
            ->where(function ($q) {
                $q->where('rc.distinct_hashes', '!=', 1)
                    ->orWhere('rc.null_count', '>', 0);
            })
            ->select('r.route_name', 'r.method', 'r.path', 'rc.repeat_count');

        if ($sinceMinutes !== null) {
            $query->where('r.created_at', '>=', now()->subMinutes($sinceMinutes));
        }

        $rows = $query->get();

        $counts = [];

        foreach ($rows as $row) {
            $label = $row->route_name ?? sprintf('%s %s', $row->method, $row->path);
            $counts[$label] = max($counts[$label] ?? 0, (int) $row->repeat_count);
        }

        return $counts;
    }

    /**
     * Return route labels that have at least one request with an exact
     * duplicate query (same fingerprint AND same bindings_hash across all
     * occurrences, meaning the fix is Cache::remember(), not with()).
     *
     * Only considers fingerprint groups that meet the repeat threshold.
     * Ignores fingerprint groups where any row has a null bindings_hash
     * (unknown — we cannot classify safely).
     *
     * @return string[] route labels
     */
    protected function routesWithDuplicateQueries(?int $sinceMinutes = null): array
    {
        $threshold = (int) config('pinpoint.n_plus_one_repeat_threshold', 3);

        // Step 1: find fingerprint+request combos that meet the repeat threshold.
        $repeatedSub = DB::table('pinpoint_queries')
            ->select('request_id', 'sql_fingerprint')
            ->selectRaw('COUNT(*) as repeat_count')
            ->selectRaw('COUNT(DISTINCT bindings_hash) as distinct_hashes')
            ->selectRaw('SUM(CASE WHEN bindings_hash IS NULL THEN 1 ELSE 0 END) as null_count')
            ->groupBy('request_id', 'sql_fingerprint')
            ->havingRaw('COUNT(*) >= ?', [$threshold]);

        // Step 2: join to requests to apply the time window filter and get the
        // route label. Filter to ONLY exact duplicates (distinct_hashes == 1
        // AND null_count == 0 → all rows have the same non-null bindings hash).
        $query = DB::table('pinpoint_requests as r')
            ->joinSub($repeatedSub, 'rq', 'rq.request_id', '=', 'r.id')
            ->where('rq.distinct_hashes', 1)
            ->where('rq.null_count', 0)
            ->select('r.route_name', 'r.method', 'r.path');

        if ($sinceMinutes !== null) {
            $query->where('r.created_at', '>=', now()->subMinutes($sinceMinutes));
        }

        $labels = [];

        foreach ($query->get() as $row) {
            $labels[] = $row->route_name ?? sprintf('%s %s', $row->method, $row->path);
        }

        return array_unique($labels);
    }
}
