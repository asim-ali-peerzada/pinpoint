<?php

namespace AsimAli\Pinpoint\Internal;

/**
 * @internal Pure comparison logic for pinpoint:diff — no DB, no I/O.
 *
 * Classification rules:
 *   - Routes only in current → NEW (informational, never a regression).
 *   - Routes only in baseline → REMOVED (informational, never a regression).
 *   - Baseline p95 = 0 → percentage is null, duration check skipped.
 *   - null memory on either side → memory check skipped.
 *   - n1_introduced (was 0, now >0) always triggers a regression, regardless
 *     of the query-count threshold.
 *   - Sort order: REGRESSION → IMPROVEMENT → STABLE → NEW → REMOVED.
 */
class DiffCalculator
{
    public const STATUS_REGRESSION = 'regression';

    public const STATUS_IMPROVEMENT = 'improvement';

    public const STATUS_STABLE = 'stable';

    public const STATUS_NEW = 'new';

    public const STATUS_REMOVED = 'removed';

    /** p95 must drop by at least this percentage to count as an improvement. */
    public const IMPROVEMENT_DURATION_PCT = 10;

    /**
     * Thresholds are optional so the calculator stays a pure unit-testable
     * class; when omitted they fall back to config (the command's path).
     *
     * @param  array<int, array<string, mixed>>  $baseline
     * @param  array<int, array<string, mixed>>  $current
     * @return array<int, array{route: string, status: string, baseline: array|null, current: array|null, changes: array}>
     */
    public function compare(
        array $baseline,
        array $current,
        ?int $durationPct = null,
        ?int $queryCount = null,
        ?int $memoryPct = null,
        ?int $minSamples = null,
    ): array {
        $durationPct ??= (int) config('pinpoint.diff.regression_duration_pct', 20);
        $queryCount ??= (int) config('pinpoint.diff.regression_query_count', 3);
        $memoryPct ??= (int) config('pinpoint.diff.regression_memory_pct', 50);
        $minSamples ??= (int) config('pinpoint.diff.min_samples', 1);

        $bMap = array_column($baseline, null, 'route');
        $cMap = array_column($current, null, 'route');
        $all = array_unique(array_merge(array_keys($bMap), array_keys($cMap)));

        $diffs = [];

        foreach ($all as $route) {
            $b = $bMap[$route] ?? null;
            $c = $cMap[$route] ?? null;

            if ($b === null) {
                $diffs[] = ['route' => $route, 'status' => self::STATUS_NEW, 'baseline' => null, 'current' => $c, 'changes' => []];

                continue;
            }

            if ($c === null) {
                $diffs[] = ['route' => $route, 'status' => self::STATUS_REMOVED, 'baseline' => $b, 'current' => null, 'changes' => []];

                continue;
            }

            $changes = $this->computeChanges($b, $c);
            $status = $this->resolveStatus($changes, $durationPct, $queryCount, $memoryPct, $minSamples, $b['samples'], $c['samples']);
            $diffs[] = ['route' => $route, 'status' => $status, 'baseline' => $b, 'current' => $c, 'changes' => $changes];
        }

        usort($diffs, fn ($a, $b) => $this->sortPriority($a['status']) <=> $this->sortPriority($b['status']));

        return $diffs;
    }

    /**
     * @param  array<string, mixed>  $b
     * @param  array<string, mixed>  $c
     * @return array{p95_delta_ms: int, p95_pct: float|null, query_delta: int, n1_delta: int, n1_introduced: bool, memory_delta_kb: int|null, memory_pct: float|null}
     */
    protected function computeChanges(array $b, array $c): array
    {
        $p95Delta = $c['p95'] - $b['p95'];
        $p95Pct = $b['p95'] > 0 ? round(($p95Delta / $b['p95']) * 100, 1) : null;

        $queryDelta = $c['query_count'] - $b['query_count'];

        $n1Delta = $c['n1_repeat'] - $b['n1_repeat'];
        $n1Introduced = $b['n1_repeat'] === 0 && $c['n1_repeat'] > 0;

        $bMem = $b['peak_memory_kb'];
        $cMem = $c['peak_memory_kb'];
        $memDelta = ($bMem !== null && $cMem !== null) ? ($cMem - $bMem) : null;
        $memPct = ($bMem !== null && $bMem > 0 && $cMem !== null)
            ? round((($cMem - $bMem) / $bMem) * 100, 1)
            : null;

        return [
            'p95_delta_ms' => $p95Delta,
            'p95_pct' => $p95Pct,
            'query_delta' => $queryDelta,
            'n1_delta' => $n1Delta,
            'n1_introduced' => $n1Introduced,
            'memory_delta_kb' => $memDelta,
            'memory_pct' => $memPct,
        ];
    }

    /**
     * @param  array<string, mixed>  $ch
     */
    protected function resolveStatus(array $ch, int $dPct, int $qCount, int $mPct, int $minSamples, int $bSamples, int $cSamples): string
    {
        // Not enough data on either side to judge — single noisy requests
        // would otherwise flag routes at the threshold. Stable is the
        // conservative verdict (never a false regression in CI).
        $insufficient = $bSamples < $minSamples || $cSamples < $minSamples;

        $durReg = $ch['p95_pct'] !== null && $ch['p95_pct'] >= $dPct;
        $q1Reg = $ch['n1_introduced'] || $ch['n1_delta'] >= $qCount;
        $memReg = $ch['memory_pct'] !== null && $ch['memory_pct'] >= $mPct;

        $improved = $ch['p95_pct'] !== null && $ch['p95_pct'] <= -self::IMPROVEMENT_DURATION_PCT;

        $isStable = $insufficient || (! $durReg && ! $q1Reg && ! $memReg && ! $improved);

        if ($isStable) {
            return self::STATUS_STABLE;
        }

        if ($durReg || $q1Reg || $memReg) {
            return self::STATUS_REGRESSION;
        }

        return self::STATUS_IMPROVEMENT;
    }

    protected function sortPriority(string $status): int
    {
        return match ($status) {
            self::STATUS_REGRESSION => 0,
            self::STATUS_IMPROVEMENT => 1,
            self::STATUS_STABLE => 2,
            self::STATUS_NEW => 3,
            self::STATUS_REMOVED => 4,
            default => 5,
        };
    }
}
