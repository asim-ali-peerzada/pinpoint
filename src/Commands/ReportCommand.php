<?php

namespace AsimAli\Pinpoint\Commands;

use AsimAli\Pinpoint\Internal\CliRenderer;
use AsimAli\Pinpoint\Internal\QueryReader;
use AsimAli\Pinpoint\Internal\SinceParser;
use AsimAli\Pinpoint\Internal\SuggestionBuilder;
use AsimAli\Pinpoint\Internal\SummaryReader;
use AsimAli\Pinpoint\TierClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ReportCommand extends Command
{
    protected $signature = 'pinpoint:report
        {--tier= : Only show routes in this tier (good|acceptable|needs_improvement|critical)}
        {--route= : Drill into one route and show its top offending queries}
        {--since= : Only consider requests from the last N (e.g. 5m, 1h, 2d; bare number = minutes)}
        {--limit=20 : Max rows in the summary table}
        {--json : Output machine-readable JSON on stdout (for scripts / webhooks / PR comments)}
        {--json-to= : Write the JSON output to a file and print the file location (human-friendly alternative; implies --json)}';

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
            $sinceMinutes = null;

            if ($this->option('since') !== null) {
                try {
                    $sinceMinutes = SinceParser::toMinutes($this->option('since'));
                } catch (InvalidArgumentException $e) {
                    $this->cli->info($e->getMessage());

                    return self::FAILURE;
                }
            }

            if ($route = $this->option('route')) {
                $this->drillInto($route, $sinceMinutes);

                return self::SUCCESS;
            }

            return $this->summary($sinceMinutes);
        } catch (Throwable $e) {
            Log::error('Pinpoint: report failed', ['exception' => $e->getMessage()]);
            $this->error('Pinpoint report failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    protected function summary(?int $sinceMinutes = null): int
    {
        $rows = $this->summaries->fromRaw($sinceMinutes);

        if ($rows === []) {
            $this->cli->info('No requests recorded yet. Run some requests, then re-run this command.');

            return self::SUCCESS;
        }

        $tier = $this->option('tier');

        // An unknown tier must fail loudly — silently rendering an empty table
        // reads as "nothing is slow" when the user typo'd the filter.
        if ($tier !== null && ! in_array(strtolower($tier), [
            TierClassifier::GOOD,
            TierClassifier::ACCEPTABLE,
            TierClassifier::NEEDS_IMPROVEMENT,
            TierClassifier::CRITICAL,
        ], true)) {
            $this->error(sprintf(
                'Invalid --tier value "%s". Valid tiers: %s.',
                $tier,
                implode(', ', [TierClassifier::GOOD, TierClassifier::ACCEPTABLE, TierClassifier::NEEDS_IMPROVEMENT, TierClassifier::CRITICAL])
            ));

            return self::FAILURE;
        }

        $filtered = [];

        foreach ($rows as $row) {
            if ($tier && $row['tier'] !== strtolower($tier)) {
                continue;
            }

            $filtered[] = $row;
        }

        $filtered = array_slice($filtered, 0, (int) $this->option('limit'));

        if ($this->option('json') || $this->option('json-to')) {
            if ((bool) config('pinpoint.composite_tier', false)) {
                // Keep CLI and JSON in agreement: when the composite Health
                // verdict is enabled, each row carries both the verdict and
                // the reason it wasn't healthy.
                $budgetKb = config('pinpoint.memory_budget_kb');
                $budgetKb = ($budgetKb === null || (int) $budgetKb === -1) ? null : (int) $budgetKb;
                $threshold = (int) config('pinpoint.n_plus_one_repeat_threshold', 3);

                $filtered = array_map(function (array $row) use ($budgetKb, $threshold) {
                    $healthy = in_array($row['tier'], [TierClassifier::GOOD, TierClassifier::ACCEPTABLE], true)
                        && $row['n1_repeat'] < $threshold
                        && ($budgetKb === null || $row['peak_memory_kb'] === null || $row['peak_memory_kb'] <= $budgetKb);

                    $row['health'] = $healthy ? 'healthy' : 'needs_work';

                    if (! $healthy) {
                        $reasons = [];

                        if (! in_array($row['tier'], [TierClassifier::GOOD, TierClassifier::ACCEPTABLE], true)) {
                            $reasons[] = 'p95 tier: '.$row['tier'];
                        }

                        if ($row['n1_repeat'] >= $threshold) {
                            $reasons[] = 'N+1 repeats: '.$row['n1_repeat'];
                        }

                        if ($budgetKb !== null && $row['peak_memory_kb'] !== null && $row['peak_memory_kb'] > $budgetKb) {
                            $reasons[] = 'peak memory: '.$row['peak_memory_kb'].' KB (budget '.$budgetKb.' KB)';
                        }

                        $row['health_reason'] = implode('; ', $reasons);
                    } else {
                        $row['health_reason'] = null;
                    }

                    return $row;
                }, $filtered);
            }

            $this->emitJson([
                'meta' => ['window_minutes' => $sinceMinutes],
                'routes' => $filtered,
            ]);

            return self::SUCCESS;
        }

        $table = [];

        // null = budget check disabled; cast only when a real limit is set.
        $memoryBudgetKb = config('pinpoint.memory_budget_kb');
        $memoryBudgetKb = $memoryBudgetKb !== null ? (int) $memoryBudgetKb : null;

        foreach ($filtered as $row) {
            $repeat = $row['n1_repeat'];
            $n1 = $repeat >= (int) config('pinpoint.n_plus_one_repeat_threshold', 3) ? "Yes (x{$repeat})" : 'No';

            $memKb = $row['peak_memory_kb'] ?? null;

            $table[] = [
                'route' => $row['route'],
                'p95' => $row['p95'],
                'avg' => $row['avg'],
                'samples' => $row['samples'],
                'tier' => $row['tier'],
                'n1' => $n1,
                'memory' => $memKb !== null ? $this->formatMemory($memKb) : null,
                'memory_over_budget' => $memKb !== null && $memoryBudgetKb !== null && $memKb > $memoryBudgetKb,
                'has_duplicate' => $row['has_duplicate_queries'],
            ];
        }

        $title = 'Performance Report';

        if ($sinceMinutes !== null) {
            $title .= ' · last '.$sinceMinutes.' min';
        }

        $this->cli->reportTable($title, $table);

        $this->printLocate($rows);

        return self::SUCCESS;
    }

    /**
     * Collect the worst offenders (N+1 or critical routes) with their
     * highest-repeat caller, for the summary "Locate" block.
     *
     * @param  array<int, array{route: string, p95: int, n1_repeat: int, tier: string}>  $summaries
     */
    protected function printLocate(array $summaries): void
    {
        $offenders = [];

        foreach ($summaries as $row) {
            if ($row['n1_repeat'] < (int) config('pinpoint.n_plus_one_repeat_threshold', 3) && $row['tier'] !== TierClassifier::CRITICAL) {
                continue;
            }

            $caller = $this->worstCaller($row['route']);

            $offenders[] = [
                'route' => $row['route'],
                'reason' => $row['n1_repeat'] > 0
                    ? 'N+1 x'.$row['n1_repeat']
                    : 'critical tier (p95 '.$row['p95'].'ms)',
                'repeat' => $row['n1_repeat'],
                'caller_file' => $caller['file'] ?? null,
                'caller_line' => $caller['line'] ?? null,
            ];
        }

        $this->cli->locate($offenders);
    }

    /**
     * Worst (highest-repeat) caller for a route label, from lazy loads first
     * then query repeats.
     *
     * @return array{file: string, line: int}|null
     */
    protected function worstCaller(string $routeLabel): ?array
    {
        $requestIds = QueryReader::scopeRouteLabel(
            DB::table('pinpoint_requests')->select('id'),
            $routeLabel
        )->orderByDesc('id')->limit(100)->pluck('id');

        if ($requestIds->isEmpty()) {
            return null;
        }

        $lazyLoad = DB::table('pinpoint_lazy_loads')
            ->whereIn('request_id', $requestIds)
            ->whereNotNull('caller_file')
            ->orderByDesc('id')
            ->first(['caller_file', 'caller_line']);

        if ($lazyLoad) {
            return ['file' => $lazyLoad->caller_file, 'line' => (int) $lazyLoad->caller_line];
        }

        $query = DB::table('pinpoint_queries')
            ->whereIn('request_id', $requestIds)
            ->whereNotNull('caller_file')
            ->select('caller_file', 'caller_line')
            ->selectRaw('COUNT(*) as c')
            ->groupBy('caller_file', 'caller_line')
            ->orderByDesc('c')
            ->first();

        return $query ? ['file' => $query->caller_file, 'line' => (int) $query->caller_line] : null;
    }

    protected function drillInto(string $routeLabel, ?int $sinceMinutes = null): void
    {
        $queries = $this->queries->topQueries($routeLabel, (int) $this->option('limit'), $sinceMinutes);

        if ($this->option('json') || $this->option('json-to')) {
            $this->emitJson([
                'route' => $routeLabel,
                'queries' => $queries->map(fn ($q) => [
                    'sql' => $q->sql,
                    'repeat_count' => $q->repeat_count,
                    'avg_ms' => (float) $q->avg_ms,
                    'max_ms' => $q->max_ms,
                    'caller_file' => $q->caller_file,
                    'caller_line' => $q->caller_line,
                    // query_type tells CI scripts whether to fix with with()
                    // or Cache::remember() without parsing the CLI badge text.
                    'query_type' => $q->query_type ?? null,
                ])->all(),
                'suggestions' => $this->buildChains($routeLabel, $sinceMinutes),
            ]);

            return;
        }

        $this->cli->info("Route: {$routeLabel}");

        if ($queries->isNotEmpty()) {
            $this->cli->queriesTable($queries, (int) config('pinpoint.n_plus_one_repeat_threshold', 3));
            $this->cli->duplicateQuerySuggestions($queries);
            $this->cli->n1QuerySuggestions($queries);
        } else {
            $this->cli->info('No queries captured for this route.');
        }

        $this->cli->suggestions($this->buildChains($routeLabel, $sinceMinutes));
    }

    /**
     * Emit the JSON payload. Two modes:
     *
     * - --json: pure JSON on stdout (the CI contract — scripts pipe it to jq
     *   or write it to a file themselves).
     * - --json-to: write to a file (auto-creating directories) and print a
     *   clear message with the resolved location, for humans.
     */
    protected function emitJson(array $payload): void
    {
        if ($path = $this->option('json-to')) {
            $path = $this->resolvePath($path);

            File::ensureDirectoryExists(dirname($path));

            file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->info(sprintf('JSON written to %s', $path));

            return;
        }

        // CI contract: plain JSON on stdout, no ANSI/HTML markup.
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function resolvePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    /**
     * Format a memory figure in KB into a human-readable string.
     *
     * Display tiers:
     *   < 1 MB    → "512 KB"
     *   >= 1 MB   → "4.2 MB" (one decimal)
     *
     * This keeps the Memory column narrow in most cases (most routes stay
     * under 10 MB), while still being readable for large values.
     */
    protected function formatMemory(int $kb): string
    {
        if ($kb < 1024) {
            return $kb.' KB';
        }

        return round($kb / 1024, 1).' MB';
    }

    /**
     * Eager-loading suggestion chains for a route label (deduped across the
     * most recent requests).
     */
    protected function buildChains(string $routeLabel, ?int $sinceMinutes = null): array
    {
        // "METHOD path" fallback labels: match at the SQL level (grouped —
        // see QueryReader::scopeRouteLabel).
        //
        // Bound the request set to the most recent ones: the whereIn lookup
        // below would otherwise grow without bound on frequently recorded
        // routes (SQLite bind limit / MySQL packet limit / memory), and
        // chaining violations from different requests would fabricate chains
        // that never occurred in a single request.
        $requestIdsQuery = QueryReader::scopeRouteLabel(
            DB::table('pinpoint_requests')->select('id'),
            $routeLabel
        )->orderByDesc('id')
            ->limit((int) $this->option('limit'));

        if ($sinceMinutes !== null) {
            $requestIdsQuery->where('created_at', '>=', now()->subMinutes($sinceMinutes));
        }

        $requestIds = $requestIdsQuery->pluck('id');

        if ($requestIds->isEmpty()) {
            return [];
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

        return array_values($chains);
    }
}
