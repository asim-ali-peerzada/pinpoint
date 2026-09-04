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
     * @return array<int, array{route: string, p50: int, p95: int, p99: int, avg: int, samples: int, tier: string, n1_repeat: int, peak_memory_kb: int|null, query_count: int, has_duplicate_queries: bool, duplicate_repeat: int, unknown_repeat: int}>
     */
    public function fromRaw(?int $sinceMinutes = null): array
    {
        $counts = $this->maxRepeatCounts($sinceMinutes);
        $duplicates = $this->duplicateRepeats($sinceMinutes);
        $unknowns = $this->unknownRepeats($sinceMinutes);

        $durationsByLabel = [];
        $peakMemoryByLabel = [];
        $queryCountByLabel = [];

        $query = DB::table('pinpoint_requests')->orderBy('id');

        if ($sinceMinutes !== null) {
            $query->where('created_at', '>=', now()->subMinutes($sinceMinutes));
        }

        // chunkById keeps memory bounded for large datasets (cursor() would
        // hold open a connection and skip late rows under concurrent writes).
        $query->chunkById(1000, function ($rows) use (&$durationsByLabel, &$peakMemoryByLabel, &$queryCountByLabel) {
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

                // Worst-case query count across samples (same convention as
                // peak memory: the diff view wants the heaviest observed run).
                $queryCountByLabel[$label] = max(
                    $queryCountByLabel[$label] ?? 0,
                    (int) ($row->query_count ?? 0)
                );
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
                // Worst-case query count across all recorded requests for this
                // route (used by the regression diff view).
                'query_count' => $queryCountByLabel[$label] ?? 0,
                // True when at least one request for this route had a repeated
                // query group classified as 'duplicate' (Cache::remember() fix).
                'has_duplicate_queries' => isset($duplicates[$label]),
                // Max repeat count among duplicate-classified groups (for the
                // summary CACHE cell and the Locate block reason).
                'duplicate_repeat' => $duplicates[$label] ?? 0,
                // Max repeat count among groups with no binding data
                // ('unknown' in the drill-down — neither provable N+1 nor
                // duplicate). Surfaced as REPEAT so the summary never calls
                // them N+1, matching the drill-down classification.
                'unknown_repeat' => $unknowns[$label] ?? 0,
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
            // True N+1 only: same fingerprint with VARYING non-null bindings.
            // Exact duplicates (cache candidates) and null-binding groups
            // (unclassifiable) are counted separately so the summary never
            // mislabels them as N+1.
            ->where('rc.distinct_hashes', '!=', 1)
            ->where('rc.null_count', 0)
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
     * Return route label => max repeat count for fingerprint groups that are
     * exact duplicates (same fingerprint AND same bindings_hash across all
     * occurrences, meaning the fix is Cache::remember(), not with()).
     *
     * Only considers fingerprint groups that meet the repeat threshold.
     * Ignores fingerprint groups where any row has a null bindings_hash
     * (unknown — we cannot classify safely).
     *
     * @return array<string, int>
     */
    protected function duplicateRepeats(?int $sinceMinutes = null): array
    {
        return $this->classifiedRepeats(
            $sinceMinutes,
            fn ($query) => $query
                ->where('rq.distinct_hashes', 1)
                ->where('rq.null_count', 0)
        );
    }

    /**
     * Return route label => max repeat count for fingerprint groups with no
     * binding data (the drill-down 'unknown' type — neither provable N+1
     * nor duplicate).
     *
     * @return array<string, int>
     */
    protected function unknownRepeats(?int $sinceMinutes = null): array
    {
        return $this->classifiedRepeats(
            $sinceMinutes,
            fn ($query) => $query->where('rq.null_count', '>', 0)
        );
    }

    /**
     * Shared per-route max-repeat aggregation over fingerprint groups that
     * meet the repeat threshold. The $constrain callback picks which
     * classification (duplicate / unknown) to keep — same rules as
     * QueryReader::topQueries() so summary and drill-down always agree.
     *
     * @return array<string, int> route label => max repeat count
     */
    protected function classifiedRepeats(?int $sinceMinutes, callable $constrain): array
    {
        $threshold = (int) config('pinpoint.n_plus_one_repeat_threshold', 3);

        // Find fingerprint+request combos that meet the repeat threshold.
        $repeatedSub = DB::table('pinpoint_queries')
            ->select('request_id', 'sql_fingerprint')
            ->selectRaw('COUNT(*) as repeat_count')
            ->selectRaw('COUNT(DISTINCT bindings_hash) as distinct_hashes')
            ->selectRaw('SUM(CASE WHEN bindings_hash IS NULL THEN 1 ELSE 0 END) as null_count')
            ->groupBy('request_id', 'sql_fingerprint')
            ->havingRaw('COUNT(*) >= ?', [$threshold]);

        // Join to requests to apply the time window filter and get the
        // route label, keeping only groups of the wanted classification.
        $query = DB::table('pinpoint_requests as r')
            ->joinSub($repeatedSub, 'rq', 'rq.request_id', '=', 'r.id');

        $constrain($query);

        $query->select('r.route_name', 'r.method', 'r.path')
            ->selectRaw('MAX(rq.repeat_count) as max_repeat');

        if ($sinceMinutes !== null) {
            $query->where('r.created_at', '>=', now()->subMinutes($sinceMinutes));
        }

        $query->groupBy('r.route_name', 'r.method', 'r.path');

        $counts = [];

        foreach ($query->get() as $row) {
            $counts[$row->route_name ?? sprintf('%s %s', $row->method, $row->path)] = (int) $row->max_repeat;
        }

        return $counts;
    }
}
