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
     * @return array<int, array{route: string, p50: int, p95: int, p99: int, avg: int, samples: int, tier: string, n1_repeat: int}>
     */
    public function fromRaw(): array
    {
        $requests = DB::table('pinpoint_requests')
            ->get(['route_name', 'method', 'path', 'duration_ms']);

        if ($requests->isEmpty()) {
            return [];
        }

        $counts = $this->maxRepeatCounts();

        $durationsByLabel = [];

        foreach ($requests as $row) {
            $label = $row->route_name ?? sprintf('%s %s', $row->method, $row->path);
            $durationsByLabel[$label][] = (int) $row->duration_ms;
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
            ];
        }

        usort($summaries, fn ($a, $b) => $b['p95'] <=> $a['p95']);

        return $summaries;
    }

    protected function maxRepeatCounts(): array
    {
        $perRequest = DB::table('pinpoint_queries')
            ->select('request_id', 'sql_fingerprint')
            ->selectRaw('COUNT(*) as repeat_count')
            ->groupBy('request_id', 'sql_fingerprint');

        $rows = DB::table('pinpoint_requests as r')
            ->joinSub($perRequest, 'rc', 'rc.request_id', '=', 'r.id')
            ->select('r.route_name', 'r.method', 'r.path', 'rc.repeat_count')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $label = $row->route_name ?? sprintf('%s %s', $row->method, $row->path);
            $counts[$label] = max($counts[$label] ?? 0, (int) $row->repeat_count);
        }

        return $counts;
    }
}
