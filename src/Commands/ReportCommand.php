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

        $routeNames = $rows->pluck('route_name')->all();
        $counts = $this->maxRepeatCounts($routeNames);

        $table = [];

        foreach ($rows as $row) {
            $durations = $this->durationsFor($row->route_name);
            $p95 = Statistics::percentile($durations, 95);
            $tier = $this->tiers->classify($p95, $row->route_name);

            if ($this->option('tier') && $tier !== strtolower($this->option('tier'))) {
                continue;
            }

            $label = $row->route_name ?? sprintf('%s %s', $row->method, $row->path);
            $repeat = $counts[$row->route_name] ?? 0;

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

    protected function maxRepeatCounts(array $routeNames): array
    {
        if ($routeNames === []) {
            return [];
        }

        $perRequest = DB::table('pinpoint_queries')
            ->select('request_id', 'sql_fingerprint')
            ->selectRaw('COUNT(*) as repeat_count')
            ->groupBy('request_id', 'sql_fingerprint');

        return DB::table('pinpoint_requests as r')
            ->joinSub($perRequest, 'rc', 'rc.request_id', '=', 'r.id')
            ->whereIn('r.route_name', $routeNames)
            ->select('r.route_name')
            ->selectRaw('MAX(rc.repeat_count) as max_repeat')
            ->groupBy('r.route_name')
            ->pluck('max_repeat', 'route_name')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    protected function durationsFor(?string $routeName): array
    {
        return DB::table('pinpoint_requests')
            ->where('route_name', $routeName)
            ->pluck('duration_ms')
            ->map(fn ($ms) => (int) $ms)
            ->all();
    }
}
