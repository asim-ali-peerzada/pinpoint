<?php

namespace AsimAli\Pinpoint\Internal;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Collection;

use function Termwind\render;

/**
 * @internal Central CLI visual language for all Pinpoint command output.
 *
 * Uses Termwind (ships with laravel/framework) for a premium terminal look:
 * tier pills, right-aligned numbers, dimmed units, N+1 emphasis.
 */
class CliRenderer
{
    public const GOOD = 'good';

    public const ACCEPTABLE = 'acceptable';

    public const NEEDS_IMPROVEMENT = 'needs_improvement';

    public const CRITICAL = 'critical';

    protected function render(string $html): void
    {
        // Termwind's Laravel provider wires render() to the running command's
        // OutputStyle, so output stays testable and verbosity-aware.
        render($html);
    }

    /**
     * @param  array<int, array{route: string, p95: int, avg: int, samples: int, tier: string, n1: string}>  $rows
     */
    public function reportTable(string $title, array $rows, ?string $emptyMessage = null): void
    {
        if ($rows === []) {
            if ($emptyMessage) {
                render(sprintf('<div class="mx-2 my-1 text-gray-500">%s</div>', e($emptyMessage)));
            }

            return;
        }

        $html = $this->header($title);

        $html .= '<table class="w-full"><thead><tr class="text-gray-500 border-b border-gray-600">'
            .'<th class="text-left">Route</th>'
            .'<th class="text-right">p95</th>'
            .'<th class="text-right">Avg</th>'
            .'<th class="text-right">Samples</th>'
            .'<th class="text-left">Tier</th>'
            .'<th class="text-center">N+1?</th>'
            .'</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>'
                .'<td class="text-left text-white">'.e($row['route']).'</td>'
                .'<td class="text-right text-white">'.$row['p95'].'<span class="text-gray-500">ms</span></td>'
                .'<td class="text-right text-white">'.$row['avg'].'<span class="text-gray-500">ms</span></td>'
                .'<td class="text-right text-gray-300">'.$row['samples'].'</td>'
                .'<td class="text-left">'.$this->tierBadge($row['tier']).'</td>'
                .'<td class="text-center">'.$this->n1Badge($row['n1']).'</td>'
                .'</tr>';
        }

        $html .= '</tbody></table>';

        render($html);
    }

    /**
     * @param  array<int, array{type: string, route: string, detail: string}>  $violations
     */
    public function checkReport(array $violations, int $checked, int $windowMinutes, bool $passed): void
    {
        $html = $this->header('Pinpoint Check');

        if ($passed) {
            $html .= '<div class="mt-1 text-green-400 font-bold">All checks passed.</div>';
        }

        foreach ($violations as $violation) {
            $badge = match ($violation['type']) {
                'n_plus_one' => '<span class="px-1 bg-red-600 text-white font-bold">N+1</span>',
                'query_budget', 'duration_budget' => '<span class="px-1 bg-yellow-600 text-black font-bold">BUDGET</span>',
                default => '<span class="px-1 bg-gray-600 text-white font-bold">VIOLATION</span>',
            };

            $html .= '<div class="mt-1">'
                .'<div class="flex"><span>'.$badge.'</span><span class="ml-1 text-white">'.e($violation['route']).'</span></div>'
                .'<div class="text-gray-400">'.e($violation['detail']).'</div>'
                .'</div>';
        }

        $html .= sprintf(
            '<div class="mt-1 text-gray-500">Checked %d request(s) in the last %d minute(s).</div>',
            $checked,
            $windowMinutes
        );

        render($html);
    }

    /**
     * @param  array<int, array{model: string, relations: string, caller_file: string|null, caller_line: int|null}>  $chains
     */
    public function suggestions(array $chains): void
    {
        if ($chains === []) {
            return;
        }

        $html = '<div class="mx-2 my-1">'
            .'<div class="mt-2"><span class="px-1 bg-yellow-600 text-black font-bold">N+1 detected</span></div>';

        foreach ($chains as $chain) {
            $caller = $chain['caller_file']
                ? sprintf(' at <span class="text-blue-400">%s:%d</span>', e($chain['caller_file']), $chain['caller_line'])
                : '';

            $html .= '<div class="mt-1 text-white">'.e($chain['model']).' -> '.e($chain['relations']).$caller.'</div>';
            $html .= '<div class="text-gray-400">Suggested fix: <span class="text-green-400">'.e($chain['model']).'::with('.e(var_export($chain['relations'], true)).')</span></div>';
        }

        $html .= '</div>';

        render($html);
    }

    public function info(string $message): void
    {
        render('<div class="mx-2 my-1 text-gray-300">'.e($message).'</div>');
    }

    /**
     * @param  Collection<int, \stdClass>  $queries
     */
    public function queriesTable($queries, int $n1Threshold): void
    {
        $html = $this->header('Top Offending Queries');

        $html .= '<table class="w-full"><thead><tr class="text-gray-500 border-b border-gray-600">'
            .'<th class="text-left">SQL</th>'
            .'<th class="text-right">Repeats</th>'
            .'<th class="text-right">Avg ms</th>'
            .'<th class="text-right">Max ms</th>'
            .'<th class="text-left">Caller</th>'
            .'</tr></thead><tbody>';

        foreach ($queries as $query) {
            $isN1 = $query->repeat_count >= $n1Threshold;
            $caller = $query->caller_file
                ? '<span class="text-blue-400">'.e($query->caller_file).':'.$query->caller_line.'</span>'
                : '<span class="text-gray-600">-</span>';

            $html .= '<tr>'
                .'<td class="text-left text-white">'.e(str_replace("\n", ' ', mb_strimwidth($query->sql, 0, 60, '…'))).'</td>'
                .'<td class="text-right">'.($isN1 ? '<span class="text-red-500 font-bold">'.$query->repeat_count.'</span>' : '<span class="text-gray-300">'.$query->repeat_count.'</span>').'</td>'
                .'<td class="text-right text-white">'.(int) round($query->avg_ms).'</td>'
                .'<td class="text-right text-white">'.$query->max_ms.'</td>'
                .'<td class="text-left">'.$caller.'</td>'
                .'</tr>';
        }

        $html .= '</tbody></table>';

        render($html);
    }

    protected function header(string $title): string
    {
        return '<div class="mx-2 my-1">'
            .'<div class="flex justify-between w-full mb-1">'
            .'<span class="px-2 bg-blue-500 text-white font-bold uppercase">Pinpoint</span>'
            .'<span class="text-gray-400">'.e($title).'</span>'
            .'</div>';
    }

    protected function tierBadge(string $tier): string
    {
        $label = strtoupper($tier);

        return match ($tier) {
            self::GOOD => '<span class="px-1 bg-green-600 text-white font-bold">'.$label.'</span>',
            self::ACCEPTABLE => '<span class="px-1 bg-yellow-600 text-black font-bold">'.$label.'</span>',
            self::NEEDS_IMPROVEMENT => '<span class="px-1 bg-orange-600 text-white font-bold">'.$label.'</span>',
            self::CRITICAL => '<span class="px-1 bg-red-600 text-white font-bold">'.$label.'</span>',
            default => '<span class="text-gray-400">'.$label.'</span>',
        };
    }

    protected function n1Badge(string $n1): string
    {
        if (str_starts_with($n1, 'Yes')) {
            return '<span class="text-red-500 font-bold">'.$n1.'</span>';
        }

        return '<span class="text-gray-600">No</span>';
    }
}
