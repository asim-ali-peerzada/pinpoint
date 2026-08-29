<?php

namespace AsimAli\Pinpoint\Commands;

use AsimAli\Pinpoint\Internal\Statistics;
use AsimAli\Pinpoint\TierClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReportCommand extends Command
{
    protected $signature = 'pinpoint:report
        {--tier= : Only show routes in this tier (good|acceptable|needs_improvement|critical)}
        {--route= : Drill into one route and show its top offending queries}
        {--limit=20 : Max rows in the summary table}';

    protected $description = 'Show per-route performance tiers computed from raw requests';

    public function __construct(protected TierClassifier $tiers)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            if ($route = $this->option('route')) {
                $this->drillInto($route);

                return self::SUCCESS;
            }

            $this->summary();
        } catch (Throwable $e) {
            Log::error('Pinpoint: report failed', ['exception' => $e->getMessage()]);
            $this->error('Pinpoint report failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function summary(): void
    {
        $rows = DB::table('pinpoint_requests')
            ->select('route_name', 'method', 'path')
            ->selectRaw('COUNT(*) as sample_count')
            ->selectRaw('AVG(duration_ms) as avg_ms')
            ->groupBy('route_name', 'method', 'path')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No requests recorded yet. Run some requests, then re-run this command.');

            return;
        }

        $counts = $this->maxRepeatCounts();

        $table = [];

        foreach ($rows as $row) {
            $label = $row->route_name ?? sprintf('%s %s', $row->method, $row->path);
            $durations = $this->durationsFor($label);
            $p95 = Statistics::percentile($durations, 95);
            $tier = $this->tiers->classify($p95, $row->route_name);

            if ($this->option('tier') && $tier !== strtolower($this->option('tier'))) {
                continue;
            }

            $repeat = $counts[$label] ?? 0;

            $table[] = [
                'route' => $label,
                'p95' => $p95,
                'avg' => (int) round($row->avg_ms),
                'samples' => (int) $row->sample_count,
                'tier' => $tier,
                'n1' => $repeat >= (int) config('pinpoint.n_plus_one_repeat_threshold', 3) ? "Yes (x$repeat)" : 'No',
            ];
        }

        usort($table, fn ($a, $b) => $b['p95'] <=> $a['p95']);

        $this->table(
            ['Route', 'p95', 'Avg', 'Samples', 'Tier', 'N+1?'],
            array_map(
                fn ($row) => [
                    $row['route'],
                    $row['p95'].'ms',
                    $row['avg'].'ms',
                    $row['samples'],
                    strtoupper($row['tier']),
                    $row['n1'],
                ],
                array_slice($table, 0, (int) $this->option('limit'))
            )
        );
    }

    protected function drillInto(string $routeName): void
    {
        $exists = DB::table('pinpoint_requests')->where('route_name', $routeName)->exists();

        if (! $exists) {
            $this->error("No requests recorded for route '{$routeName}'.");

            return;
        }

        $queries = DB::table('pinpoint_queries as q')
            ->join('pinpoint_requests as r', 'r.id', '=', 'q.request_id')
            ->where('r.route_name', $routeName)
            ->select('q.sql_fingerprint', 'q.sql', 'q.caller_file', 'q.caller_line')
            ->selectRaw('COUNT(*) as repeat_count')
            ->selectRaw('MAX(q.time_ms) as max_ms')
            ->selectRaw('AVG(q.time_ms) as avg_ms')
            ->groupBy('q.sql_fingerprint', 'q.sql', 'q.caller_file', 'q.caller_line')
            ->orderByDesc('max_ms')
            ->limit((int) $this->option('limit'))
            ->get();

        $this->info("Route: {$routeName}");

        if ($queries->isEmpty()) {
            $this->info('No queries captured for this route.');

            return;
        }

        $this->table(
            ['Fingerprint', 'SQL', 'Repeats', 'Avg ms', 'Max ms', 'Caller'],
            $queries->map(fn ($q) => [
                substr($q->sql_fingerprint, 0, 8),
                str_replace("\n", ' ', mb_strimwidth($q->sql, 0, 70, '…')),
                $q->repeat_count,
                (int) round($q->avg_ms),
                $q->max_ms,
                $q->caller_file ? $q->caller_file.':'.$q->caller_line : '-',
            ])->all()
        );
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

    protected function durationsFor(string $label): array
    {
        return DB::table('pinpoint_requests')
            ->get(['route_name', 'method', 'path', 'duration_ms'])
            ->filter(fn ($row) => ($row->route_name ?? sprintf('%s %s', $row->method, $row->path)) === $label)
            ->pluck('duration_ms')
            ->map(fn ($ms) => (int) $ms)
            ->all();
    }
}
