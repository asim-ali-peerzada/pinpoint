<?php

namespace AsimAli\Pinpoint\Commands;

use AsimAli\Pinpoint\Commands\Concerns\EmitsJson;
use AsimAli\Pinpoint\Internal\BadgeRenderer;
use AsimAli\Pinpoint\Internal\CliRenderer;
use AsimAli\Pinpoint\Internal\QueryReader;
use AsimAli\Pinpoint\Internal\SinceParser;
use AsimAli\Pinpoint\Internal\SuggestionBuilder;
use AsimAli\Pinpoint\Internal\SummaryReader;
use AsimAli\Pinpoint\TierClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ReportCommand extends Command
{
    use EmitsJson;

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
            $sinceMinutes = $this->resolveSinceMinutes();

            if ($route = $this->option('route')) {
                $this->drillInto($route, $sinceMinutes);

                return self::SUCCESS;
            }

            return $this->summary($sinceMinutes);
        } catch (Throwable $e) {
            return $this->handleException($e);
        }
    }

    protected function handleException(Throwable $e): int
    {
        if ($e instanceof InvalidArgumentException) {
            $this->cli->info($e->getMessage());
        } else {
            Log::error('Pinpoint: report failed', ['exception' => $e->getMessage()]);
            $this->error('Pinpoint report failed: '.$e->getMessage());
        }

        return self::FAILURE;
    }

    protected function resolveSinceMinutes(): ?int
    {
        $since = $this->option('since');

        return $since !== null ? SinceParser::toMinutes($since) : null;
    }

    protected function summary(?int $sinceMinutes = null): int
    {
        $rows = $this->summaries->fromRaw($sinceMinutes);

        if ($rows === []) {
            // The JSON contract must hold on empty data too — CI pipes this
            // to jq, so plain text here would break parsers.
            if ($this->option('json') || $this->option('json-to')) {
                $this->emitJson([
                    'meta' => ['window_minutes' => $sinceMinutes, 'empty' => true],
                    'routes' => [],
                ]);
            } else {
                $this->cli->info('No requests recorded yet. Run some requests, then re-run this command.');
            }

            return self::SUCCESS;
        }

        $tier = $this->validateTier($this->option('tier'));
        $filtered = $this->filterRows($rows, $tier, (int) $this->option('limit'));

        if ($this->option('json') || $this->option('json-to')) {
            $this->emitJsonSummary($filtered, $sinceMinutes);
        } else {
            $this->renderTableSummary($filtered, $rows, $sinceMinutes);
        }

        return self::SUCCESS;
    }

    protected function validateTier(?string $tier): ?string
    {
        if ($tier === null) {
            return null;
        }

        $validTiers = [
            TierClassifier::GOOD,
            TierClassifier::ACCEPTABLE,
            TierClassifier::NEEDS_IMPROVEMENT,
            TierClassifier::CRITICAL,
        ];

        $normalized = strtolower($tier);

        if (! in_array($normalized, $validTiers, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid --tier value "%s". Valid tiers: %s.',
                $tier,
                implode(', ', $validTiers)
            ));
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{route: string, p50: int, p95: int, p99: int, avg: int, samples: int, tier: string, n1_repeat: int, peak_memory_kb: int|null, has_duplicate_queries: bool, duplicate_repeat: int, unknown_repeat: int}>  $rows
     * @return array<int, array{route: string, p50: int, p95: int, p99: int, avg: int, samples: int, tier: string, n1_repeat: int, peak_memory_kb: int|null, has_duplicate_queries: bool, duplicate_repeat: int, unknown_repeat: int}>
     */
    protected function filterRows(array $rows, ?string $tier, int $limit): array
    {
        $filtered = [];

        foreach ($rows as $row) {
            if ($tier !== null && $row['tier'] !== $tier) {
                continue;
            }

            $filtered[] = $row;
        }

        return array_slice($filtered, 0, $limit);
    }

    /**
     * @param  array<int, array{route: string, p50: int, p95: int, p99: int, avg: int, samples: int, tier: string, n1_repeat: int, peak_memory_kb: int|null, has_duplicate_queries: bool, duplicate_repeat: int, unknown_repeat: int}>  $filtered
     */
    protected function emitJsonSummary(array $filtered, ?int $sinceMinutes): void
    {
        if ((bool) config('pinpoint.composite_tier', false)) {
            $filtered = $this->applyCompositeHealth($filtered);
        }

        $this->emitJson([
            'meta' => ['window_minutes' => $sinceMinutes],
            'routes' => $filtered,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function applyCompositeHealth(array $rows): array
    {
        $budgetKb = config('pinpoint.memory_budget_kb');
        $budgetKb = ($budgetKb === null || (int) $budgetKb === -1) ? null : (int) $budgetKb;
        $threshold = (int) config('pinpoint.n_plus_one_repeat_threshold', 3);

        return array_map(function (array $row) use ($budgetKb, $threshold) {
            $healthy = in_array($row['tier'], [TierClassifier::GOOD, TierClassifier::ACCEPTABLE], true)
                && $row['n1_repeat'] < $threshold
                && ($budgetKb === null || $row['peak_memory_kb'] === null || $row['peak_memory_kb'] <= $budgetKb);

            $row['health'] = $healthy ? 'healthy' : 'needs_work';
            $row['health_reason'] = $healthy ? null : $this->buildHealthReason($row, $budgetKb, $threshold);

            return $row;
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function buildHealthReason(array $row, ?int $budgetKb, int $threshold): string
    {
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

        return implode('; ', $reasons);
    }

    /**
     * @param  array<int, array{route: string, p50: int, p95: int, p99: int, avg: int, samples: int, tier: string, n1_repeat: int, peak_memory_kb: int|null, has_duplicate_queries: bool, duplicate_repeat: int, unknown_repeat: int}>  $filtered
     * @param  array<int, array{route: string, p50: int, p95: int, p99: int, avg: int, samples: int, tier: string, n1_repeat: int, peak_memory_kb: int|null, has_duplicate_queries: bool, duplicate_repeat: int, unknown_repeat: int}>  $allRows
     */
    protected function renderTableSummary(array $filtered, array $allRows, ?int $sinceMinutes): void
    {
        $memoryBudgetKb = config('pinpoint.memory_budget_kb');
        $memoryBudgetKb = $memoryBudgetKb !== null ? (int) $memoryBudgetKb : null;

        $table = $this->buildTableRows($filtered, $memoryBudgetKb);

        $title = 'Performance Report';
        if ($sinceMinutes !== null) {
            $title .= ' · last '.$sinceMinutes.' min';
        }

        $this->cli->reportTable($title, $table);
        $this->printLocate($allRows);
    }

    /**
     * @param  array<int, array{route: string, p50: int, p95: int, p99: int, avg: int, samples: int, tier: string, n1_repeat: int, peak_memory_kb: int|null, has_duplicate_queries: bool, duplicate_repeat: int, unknown_repeat: int}>  $filtered
     * @return array<int, array{route: string, p95: int, avg: int, samples: int, tier: string, n1: string, memory: string|null, memory_over_budget: bool, has_duplicate: bool}>
     */
    protected function buildTableRows(array $filtered, ?int $memoryBudgetKb): array
    {
        $threshold = (int) config('pinpoint.n_plus_one_repeat_threshold', 3);
        $table = [];

        foreach ($filtered as $row) {
            $repeat = $row['n1_repeat'];
            $dupRepeat = $row['duplicate_repeat'];
            $unknownRepeat = $row['unknown_repeat'];

            // Exact duplicates (same bindings) are cache candidates, not
            // N+1 — label them CACHE so they never read as "Yes (xN)".
            // Null-binding groups are unclassifiable — label them REPEAT
            // to match the drill-down, never N+1.
            if ($repeat >= $threshold) {
                $n1 = "Yes (x{$repeat})";
            } elseif ($dupRepeat >= $threshold) {
                $n1 = "CACHE (x{$dupRepeat})";
            } elseif ($unknownRepeat >= $threshold) {
                $n1 = "REPEAT (x{$unknownRepeat})";
            } else {
                $n1 = 'No';
            }

            $memKb = $row['peak_memory_kb'];

            $table[] = [
                'route' => $row['route'],
                'p95' => $row['p95'],
                'avg' => $row['avg'],
                'samples' => $row['samples'],
                'tier' => $row['tier'],
                'n1' => $n1,
                'memory' => $memKb !== null ? BadgeRenderer::formatMemory($memKb) : null,
                'memory_over_budget' => $memKb !== null && $memoryBudgetKb !== null && $memKb > $memoryBudgetKb,
                'has_duplicate' => $row['has_duplicate_queries'],
            ];
        }

        return $table;
    }

    /**
     * Collect the worst offenders (N+1 or critical routes) with their
     * highest-repeat caller, for the summary "Locate" block.
     *
     * @param  array<int, array{route: string, p95: int, n1_repeat: int, tier: string, duplicate_repeat: int, unknown_repeat: int, has_duplicate_queries: bool}>  $summaries
     */
    protected function printLocate(array $summaries): void
    {
        $threshold = (int) config('pinpoint.n_plus_one_repeat_threshold', 3);

        $offenders = [];

        foreach ($summaries as $row) {
            // duplicateRepeats()/unknownRepeats() only return groups at/above
            // the threshold, so the flags imply their counts qualify.
            $dupRepeat = $row['duplicate_repeat'];
            $hasDuplicate = $row['has_duplicate_queries'];
            $unknownRepeat = $row['unknown_repeat'];
            $hasUnknown = $unknownRepeat >= $threshold;
            $isN1 = $row['n1_repeat'] >= $threshold;
            $isCritical = $row['tier'] === TierClassifier::CRITICAL;

            if (! $isN1 && ! $hasDuplicate && ! $hasUnknown && ! $isCritical) {
                continue;
            }

            // One entry per signal: a mixed route appears under every group
            // it belongs to, so each banner count stays locatable.
            if ($isN1) {
                $caller = $this->worstCaller($row['route']);

                $offenders[] = [
                    'route' => $row['route'],
                    'reason' => 'N+1 x'.$row['n1_repeat'],
                    'repeat' => $row['n1_repeat'],
                    'caller_file' => $caller['file'] ?? null,
                    'caller_line' => $caller['line'] ?? null,
                ];
            }

            if ($hasDuplicate) {
                $caller = $this->worstDuplicateCaller($row['route']) ?? $this->worstCaller($row['route']);

                $offenders[] = [
                    'route' => $row['route'],
                    'reason' => 'CACHE x'.$dupRepeat,
                    'repeat' => $dupRepeat,
                    'caller_file' => $caller['file'] ?? null,
                    'caller_line' => $caller['line'] ?? null,
                ];
            }

            if ($hasUnknown) {
                $caller = $this->worstCaller($row['route']);

                $offenders[] = [
                    'route' => $row['route'],
                    'reason' => 'REPEAT x'.$unknownRepeat,
                    'repeat' => $unknownRepeat,
                    'caller_file' => $caller['file'] ?? null,
                    'caller_line' => $caller['line'] ?? null,
                ];
            }

            if (! $isN1 && ! $hasDuplicate && ! $hasUnknown && $isCritical) {
                $caller = $this->worstCaller($row['route']);

                $offenders[] = [
                    'route' => $row['route'],
                    'reason' => 'critical tier (p95 '.$row['p95'].'ms)',
                    'repeat' => $row['n1_repeat'],
                    'caller_file' => $caller['file'] ?? null,
                    'caller_line' => $caller['line'] ?? null,
                ];
            }
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
        return QueryReader::worstCaller($routeLabel);
    }

    /**
     * Caller of the highest-repeat exact-duplicate group for a route label.
     * A mixed route's overall worst caller usually belongs to its N+1
     * group, so the CACHE entry needs its own lookup to point at the
     * right line. Null when no duplicate group is found (the caller then
     * falls back to the overall worst).
     *
     * @return array{file: string, line: int}|null
     */
    protected function worstDuplicateCaller(string $routeLabel): ?array
    {
        $duplicate = $this->queries
            ->topQueries($routeLabel, 100)
            ->filter(fn ($query) => ($query->query_type ?? null) === 'duplicate')
            ->sortByDesc('repeat_count')
            ->first();

        if (! $duplicate || ! $duplicate->caller_file) {
            return null;
        }

        return ['file' => $duplicate->caller_file, 'line' => (int) $duplicate->caller_line];
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
     * Eager-loading suggestion chains for a route label (deduped across the
     * most recent requests).
     */
    protected function buildChains(string $routeLabel, ?int $sinceMinutes = null): array
    {
        return $this->suggestions->forRoute($routeLabel, (int) $this->option('limit'), $sinceMinutes);
    }
}
