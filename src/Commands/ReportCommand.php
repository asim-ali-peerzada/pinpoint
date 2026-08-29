<?php

namespace AsimAli\Pinpoint\Commands;

use AsimAli\Pinpoint\Internal\QueryReader;
use AsimAli\Pinpoint\Internal\SummaryReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReportCommand extends Command
{
    protected $signature = 'pinpoint:report
        {--tier= : Only show routes in this tier (good|acceptable|needs_improvement|critical)}
        {--route= : Drill into one route and show its top offending queries}
        {--limit=20 : Max rows in the summary table}';

    protected $description = 'Show per-route performance tiers computed from raw requests';

    public function __construct(
        protected SummaryReader $summaries,
        protected QueryReader $queries,
    ) {
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
        $rows = $this->summaries->fromRaw();

        if ($rows === []) {
            $this->info('No requests recorded yet. Run some requests, then re-run this command.');

            return;
        }

        $tier = $this->option('tier');

        $table = [];

        foreach ($rows as $row) {
            if ($tier && $row['tier'] !== strtolower($tier)) {
                continue;
            }

            $table[] = [
                'route' => $row['route'],
                'p95' => $row['p95'],
                'avg' => $row['avg'],
                'samples' => $row['samples'],
                'tier' => $row['tier'],
                'n1' => $row['n1_repeat'] >= (int) config('pinpoint.n_plus_one_repeat_threshold', 3) ? "Yes (x{$row['n1_repeat']})" : 'No',
            ];
        }

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

    protected function drillInto(string $routeLabel): void
    {
        $queries = $this->queries->topQueries($routeLabel, (int) $this->option('limit'));

        $this->info("Route: {$routeLabel}");

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
}
