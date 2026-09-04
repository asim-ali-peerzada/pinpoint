<?php

namespace AsimAli\Pinpoint\Commands;

use AsimAli\Pinpoint\Commands\Concerns\EmitsJson;
use AsimAli\Pinpoint\Exceptions\BaselineException;
use AsimAli\Pinpoint\Internal\BaselineReader;
use AsimAli\Pinpoint\Internal\DiffCalculator;
use AsimAli\Pinpoint\Internal\DiffRenderer;
use AsimAli\Pinpoint\Internal\QueryReader;
use AsimAli\Pinpoint\Internal\SinceParser;
use AsimAli\Pinpoint\Internal\SuggestionBuilder;
use AsimAli\Pinpoint\Internal\SummaryReader;
use Illuminate\Console\Command;
use InvalidArgumentException;

class DiffCommand extends Command
{
    use EmitsJson;

    protected $signature = 'pinpoint:diff
        {--baseline=main : Tag of the snapshot to compare against}
        {--since= : Only compare requests from the last N (e.g. 30m, 1h)}
        {--fail-on-regression : Exit 1 when any regression is found (for CI)}
        {--json : Output machine-readable JSON on stdout}
        {--json-to= : Write the JSON output to a file}
        {--show-stable : Include stable routes in the table (hidden by default)}';

    protected $description = 'Compare current performance against a baseline snapshot';

    public function __construct(
        protected BaselineReader $baselines,
        protected SummaryReader $summaries,
        protected DiffCalculator $calculator,
        protected SuggestionBuilder $suggestions,
        protected DiffRenderer $cli,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $baselineTag = (string) $this->option('baseline');

        try {
            $sinceMinutes = $this->resolveSinceMinutes();
            $baseline = $this->baselines->load($baselineTag);
            $current = $this->summaries->fromRaw($sinceMinutes);
            $diffs = $this->calculator->compare($baseline, $current);
        } catch (InvalidArgumentException|BaselineException $e) {
            // The JSON contract must hold on errors too — CI pipes this
            // to jq, so plain text here would break parsers.
            if ($this->option('json') || $this->option('json-to')) {
                $this->emitJson([
                    'meta' => [
                        'baseline_tag' => $baselineTag,
                        'generated_at' => now()->toIso8601String(),
                        'window_minutes' => null,
                        'error' => $e->getMessage(),
                    ],
                    'routes' => [],
                ]);
            } else {
                $this->cli->info($e->getMessage());
            }

            return self::FAILURE;
        }

        $regressions = array_values(array_filter(
            $diffs,
            fn (array $d) => $d['status'] === DiffCalculator::STATUS_REGRESSION
        ));

        if ($this->option('json') || $this->option('json-to')) {
            $this->emitJson($this->jsonPayload($baselineTag, $sinceMinutes, $diffs));
        } else {
            $this->cli->diffTable($baselineTag, $diffs, (bool) $this->option('show-stable'));

            if ($regressions !== []) {
                $this->cli->regressionDetails($this->regressionContext($regressions));
            }
        }

        if ($this->option('fail-on-regression') && $regressions !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{route: string, status: string, baseline: array|null, current: array|null, changes: array}>  $diffs
     * @return array<string, mixed>
     */
    protected function jsonPayload(string $baselineTag, ?int $sinceMinutes, array $diffs): array
    {
        $counts = array_count_values(array_column($diffs, 'status'));

        return [
            'meta' => [
                'baseline_tag' => $baselineTag,
                'generated_at' => now()->toIso8601String(),
                'window_minutes' => $sinceMinutes,
                'regression_count' => $counts[DiffCalculator::STATUS_REGRESSION] ?? 0,
                'improvement_count' => $counts[DiffCalculator::STATUS_IMPROVEMENT] ?? 0,
                'stable_count' => $counts[DiffCalculator::STATUS_STABLE] ?? 0,
                'new_count' => $counts[DiffCalculator::STATUS_NEW] ?? 0,
                'removed_count' => $counts[DiffCalculator::STATUS_REMOVED] ?? 0,
            ],
            'routes' => $diffs,
        ];
    }

    /**
     * Enrich regression rows with caller + eager-load fix for the terminal
     * detail block. The renderer stays pure — all lookups happen here.
     *
     * @param  array<int, array{route: string, baseline: array|null, current: array|null, changes: array}>  $regressions
     * @return array<int, array{route: string, baseline: array, current: array, changes: array, caller_file: string|null, caller_line: int|null, fix: string|null}>
     */
    protected function regressionContext(array $regressions): array
    {
        $context = [];

        foreach ($regressions as $regression) {
            $caller = QueryReader::worstCaller($regression['route']);
            $chains = $this->suggestions->forRoute($regression['route'], 5);

            $context[] = [
                'route' => $regression['route'],
                'baseline' => $regression['baseline'],
                'current' => $regression['current'],
                'changes' => $regression['changes'],
                'caller_file' => $caller['file'] ?? null,
                'caller_line' => $caller['line'] ?? null,
                'fix' => $chains === []
                    ? null
                    : sprintf('%s::with(%s)', $chains[0]['model'], var_export($chains[0]['relations'], true)),
            ];
        }

        return $context;
    }

    protected function resolveSinceMinutes(): ?int
    {
        $since = $this->option('since');

        return $since !== null ? SinceParser::toMinutes($since) : null;
    }
}
