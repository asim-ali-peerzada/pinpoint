<?php

namespace AsimAli\Pinpoint\Internal;

/**
 * @internal Renders terminal output for the pinpoint:diff command,
 * including summary comparison tables and regression detail blocks.
 */
class DiffRenderer extends CliRenderer
{
    /**
     * Diff summary table: baseline vs current per route, with a status pill
     * and a compact change cell (duration %, query delta). Stable routes are
     * hidden unless explicitly shown.
     *
     * @param  array<int, array{route: string, status: string, baseline: array|null, current: array|null, changes: array}>  $diffs
     */
    public function diffTable(string $baselineTag, array $diffs, bool $showStable): void
    {
        $visible = $showStable
            ? $diffs
            : array_values(array_filter($diffs, fn ($d) => $d['status'] !== DiffCalculator::STATUS_STABLE));

        if ($visible === []) {
            $this->info('No changes detected against baseline "'.$baselineTag.'".');

            return;
        }

        $counts = array_count_values(array_column($diffs, 'status'));

        $html = BadgeRenderer::header('Performance Diff: '.$baselineTag.' → current')
            .sprintf(
                '<div class="mt-1 mb-1 text-gray-400">%d regression(s) · %d improvement(s) · %d stable · %d new · %d removed</div>',
                $counts[DiffCalculator::STATUS_REGRESSION] ?? 0,
                $counts[DiffCalculator::STATUS_IMPROVEMENT] ?? 0,
                $counts[DiffCalculator::STATUS_STABLE] ?? 0,
                $counts[DiffCalculator::STATUS_NEW] ?? 0,
                $counts[DiffCalculator::STATUS_REMOVED] ?? 0
            )
            .'<hr>'
            .'<table class="w-full" style="compact"><thead><tr class="text-gray-500 border-b border-gray-600">'
            .'<th class="text-left">Route</th>'
            .'<th class="text-left">Status</th>'
            .'<th class="text-right">Baseline</th>'
            .'<th class="text-right">Current</th>'
            .'<th class="text-right">Change</th>'
            .'</tr></thead><tbody>';

        foreach ($visible as $diff) {
            $html .= '<tr>'
                .'<td class="text-left text-white">'.$this->routeLink($diff['route']).'</td>'
                .'<td class="text-left">'.BadgeRenderer::diffStatus($diff['status']).'</td>'
                .'<td class="text-right">'.$this->metricCell($diff['baseline']).'</td>'
                .'<td class="text-right">'.$this->metricCell($diff['current']).'</td>'
                .'<td class="text-right">'.$this->changeCell($diff['changes']).'</td>'
                .'</tr>';
        }

        $html .= '</tbody></table><hr></div>';

        $this->render($html);
    }

    /**
     * "p95 Xms · Yq" cell for a baseline/current side. Null side (NEW or
     * REMOVED routes) renders an em dash.
     *
     * @param  array<string, mixed>|null  $side
     */
    protected function metricCell(?array $side): string
    {
        if ($side === null) {
            return '<span class="text-gray-600">—</span>';
        }

        // Termwind drops sibling spans inside table cells — the gray suffix
        // must nest inside the white span (same pattern as healthOrTier).
        return '<span class="text-white">'.$side['p95']
            .'<span class="text-gray-500">ms · '.$side['query_count'].'q</span></span>';
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    protected function changeCell(array $changes): string
    {
        $parts = [];

        // NEW/REMOVED rows carry an empty changes array — read defensively.
        if (($changes['p95_pct'] ?? null) !== null) {
            $parts[] = $this->changeBadge(sprintf('%+.1f%%', $changes['p95_pct']));
        }

        if (($changes['query_delta'] ?? 0) !== 0) {
            $parts[] = $this->changeBadge(sprintf('%+dq', $changes['query_delta']));
        }

        if ($parts === []) {
            return '<span class="text-gray-600">—</span>';
        }

        // Single parent span: Termwind drops sibling spans inside a cell.
        return '<span>'.implode(' ', $parts).'</span>';
    }

    protected function changeBadge(string $change): string
    {
        $class = str_starts_with($change, '-') ? 'text-green-500' : 'text-red-500';

        return '<span class="'.$class.' font-bold">'.e($change).'</span>';
    }

    /**
     * Per-regression detail block under the diff table: numbers, caller, and
     * the eager-load fix when one is known.
     *
     * @param  array<int, array{route: string, baseline: array, current: array, changes: array, caller_file: string|null, caller_line: int|null, fix: string|null}>  $details
     */
    public function regressionDetails(array $details): void
    {
        if ($details === []) {
            return;
        }

        $html = '<div class="mx-2 my-1">'
            .'<div class="mt-2"><span class="px-1 bg-red-600 text-white font-bold">Regressions detected</span></div>';

        foreach ($details as $detail) {
            $b = $detail['baseline'];
            $c = $detail['current'];
            $ch = $detail['changes'];

            $html .= '<div class="mt-2"><span class="text-red-500 font-bold">✗</span> '
                .'<span class="text-white">'.$this->routeLink($detail['route']).'</span></div>';

            $html .= $this->detailLine(
                'Duration',
                $b['p95'].'ms → '.$c['p95'].'ms',
                $ch['p95_pct'] !== null ? sprintf('%+.1f%%', $ch['p95_pct']) : null
            );

            $html .= $this->detailLine(
                'Queries',
                $b['query_count'].' → '.$c['query_count'],
                sprintf('%+d', $ch['query_delta'])
            );

            if ($ch['n1_delta'] !== 0) {
                $html .= $this->detailLine(
                    'N+1',
                    $this->n1Label($b['n1_repeat']).' → '.$this->n1Label($c['n1_repeat']),
                    sprintf('%+d', $ch['n1_delta'])
                );
            }

            if ($ch['memory_delta_kb'] !== null) {
                $html .= $this->detailLine(
                    'Memory',
                    BadgeRenderer::formatMemory((int) $b['peak_memory_kb']).' → '.BadgeRenderer::formatMemory((int) $c['peak_memory_kb']),
                    $ch['memory_pct'] !== null ? sprintf('%+.1f%%', $ch['memory_pct']) : null
                );
            }

            if ($detail['caller_file']) {
                $html .= '<div class="text-gray-400">Caller: '.$this->callerLink($detail['caller_file'], $detail['caller_line']).'</div>';
            }

            if ($detail['fix']) {
                $html .= '<div class="text-gray-400">Suggested fix: <span class="text-green-400">'.e($detail['fix']).'</span></div>';
            }
        }

        $html .= '</div>';

        $this->render($html);
    }

    protected function detailLine(string $label, string $value, ?string $change): string
    {
        return '<div class="text-gray-400"><span class="text-gray-600">'.$label.':</span> '
            .'<span class="text-white">'.e($value).'</span>'
            .($change !== null ? ' '.$this->changeBadge($change) : '')
            .'</div>';
    }

    protected function n1Label(int $repeat): string
    {
        return $repeat > 0 ? 'Yes (x'.$repeat.')' : 'No';
    }
}
