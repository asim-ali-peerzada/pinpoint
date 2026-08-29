<?php

namespace AsimAli\Pinpoint\Commands;

use AsimAli\Pinpoint\Internal\CliRenderer;
use AsimAli\Pinpoint\Internal\QueryReader;
use AsimAli\Pinpoint\Internal\SuggestionBuilder;
use AsimAli\Pinpoint\Internal\SummaryReader;
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

    public function __construct(
        protected SummaryReader $summaries,
        protected QueryReader $queries,
        protected SuggestionBuilder $suggestions,
        protected CliRenderer $cli,
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
            $this->cli->info('No requests recorded yet. Run some requests, then re-run this command.');

            return;
        }

        $tier = $this->option('tier');

        $table = [];

        foreach ($rows as $row) {
            if ($tier && $row['tier'] !== strtolower($tier)) {
                continue;
            }

            $repeat = $row['n1_repeat'];
            $n1 = $repeat >= (int) config('pinpoint.n_plus_one_repeat_threshold', 3) ? "Yes (x{$repeat})" : 'No';

            $table[] = [
                'route' => $row['route'],
                'p95' => $row['p95'],
                'avg' => $row['avg'],
                'samples' => $row['samples'],
                'tier' => $row['tier'],
                'n1' => $n1,
            ];
        }

        $this->cli->reportTable('Performance Report', array_slice($table, 0, (int) $this->option('limit')));
    }

    protected function drillInto(string $routeLabel): void
    {
        $queries = $this->queries->topQueries($routeLabel, (int) $this->option('limit'));

        $this->cli->info("Route: {$routeLabel}");

        if ($queries->isNotEmpty()) {
            $this->cli->queriesTable($queries, (int) config('pinpoint.n_plus_one_repeat_threshold', 3));
        } else {
            $this->cli->info('No queries captured for this route.');
        }

        $this->printSuggestions($routeLabel);
    }

    protected function printSuggestions(string $routeLabel): void
    {
        $query = DB::table('pinpoint_requests')->select('id')
            ->where('route_name', $routeLabel);

        // "METHOD path" fallback labels: match at the SQL level.
        if (str_contains($routeLabel, ' ')) {
            [$method, $path] = explode(' ', $routeLabel, 2);

            $query->orWhere(fn ($q) => $q
                ->whereNull('route_name')
                ->where('method', $method)
                ->where('path', $path));
        }

        // Bound the request set to the most recent ones: the whereIn lookup
        // below would otherwise grow without bound on frequently recorded
        // routes (SQLite bind limit / MySQL packet limit / memory), and
        // chaining violations from different requests would fabricate chains
        // that never occurred in a single request.
        $requestIds = $query->orderByDesc('id')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        if ($requestIds->isEmpty()) {
            return;
        }

        // Build chains per request, then merge: a suggestion must reflect a
        // chain that actually happened inside one request.
        $violations = DB::table('pinpoint_lazy_loads')
            ->whereIn('request_id', $requestIds)
            ->select('request_id', 'model', 'relation', 'caller_file', 'caller_line')
            ->get()
            ->map(fn ($row) => [
                'request_id' => $row->request_id,
                'model' => $row->model,
                'relation' => $row->relation,
                'caller_file' => $row->caller_file,
                'caller_line' => $row->caller_line,
            ])
            ->unique(fn ($row) => $row['request_id'].'|'.$row['model'].'->'.$row['relation'])
            ->groupBy('request_id');

        $chains = [];

        foreach ($violations as $rows) {
            foreach ($this->suggestions->build($rows->values()->all()) as $chain) {
                $key = $chain['model'].'|'.$chain['relations'];

                if (! isset($chains[$key])) {
                    $chains[$key] = $chain;
                }
            }
        }

        $this->cli->suggestions(array_values($chains));
    }
}
