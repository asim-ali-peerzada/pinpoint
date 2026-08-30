<?php

namespace AsimAli\Pinpoint\Internal;

use AsimAli\Pinpoint\TierClassifier;
use Illuminate\Support\Collection;
use Termwind\Termwind;

use function Termwind\parse;

/**
 * @internal Central CLI visual language for all Pinpoint command output.
 *
 * Uses Termwind (ships with laravel/framework) for a premium terminal look:
 * tier pills, right-aligned numbers, dimmed units, N+1 emphasis.
 */
class CliRenderer
{
    /** @var array<string, string> token => OSC 8 sequence */
    protected array $hyperlinks = [];

    protected function render(string $html): void
    {
        // Termwind's Laravel provider wires render() to the running command's
        // OutputStyle, so output stays testable and verbosity-aware.
        //
        // Parse first, then swap hyperlink tokens: Termwind's own render()
        // would strip the raw OSC 8 bytes if they were in the HTML, and its
        // table component re-renders cells after HTML parsing.
        $rendered = parse($html);

        // Termwind::getRenderer() is NOT public API — the whole Termwind class
        // is marked @internal in its source (nunomaduro/termwind, src/Termwind.php).
        // It's used because render()/parse() strip OSC 8 bytes during their HTML
        // parse phase, so the sequences can only be injected after parsing.
        // Revisit if Termwind ever adds native OSC 8 hyperlink support; spec:
        // https://gist.github.com/egmontkob/eb114294efbcd5adb1944c9f3cb5feda
        Termwind::getRenderer()->writeln($this->replaceHyperlinks($rendered));

        // Clear after the swap (not before render): callerLink() populates the
        // map during HTML construction, before render() runs — clearing first
        // would drop the tokens and leak literal "__PINPOINT_LINK_n__" strings.
        // Clearing after keeps the map from growing unbounded when one renderer
        // instance serves multiple renders (Octane workers, test suites).
        $this->hyperlinks = [];
    }

    /**
     * Replace hyperlink tokens (inserted by callerLink) with raw OSC 8
     * sequences AFTER Termwind has rendered — raw ESC bytes don't survive
     * Termwind's HTML/DOM parsing, so links are injected at the last step.
     */
    protected function replaceHyperlinks(string $html): string
    {
        foreach ($this->hyperlinks as $token => $osc8) {
            $html = str_replace($token, $osc8, $html);
        }

        return $html;
    }

    /**
     * Render a caller as a clickable OSC 8 terminal hyperlink.
     *
     * Termwind stores <a href> as a property but never emits the OSC 8
     * sequence (verified in its Styles implementation), so links are
     * injected as tokens before rendering and swapped for raw OSC 8
     * sequences afterwards.
     *
     * Never spawns a shell command — the host terminal resolves the URI
     * scheme, so it works from inside Docker/Sail/WSL.
     */
    protected function callerLink(?string $file, ?int $line): string
    {
        // Without a line number the OSC 8 target would be "file:0" (%d casts
        // null) — no link is better than a link that jumps nowhere.
        if (! $file || $line === null) {
            return '<span class="text-gray-600">-</span>';
        }

        $scheme = $this->editorScheme($file, $line);

        $token = '__PINPOINT_LINK_'.count($this->hyperlinks).'__';

        $this->hyperlinks[$token] = sprintf(
            "\033]8;;%s\033\\\033[4m%s:%d\033[0m\033]8;;\033\\",
            $scheme,
            $file,
            $line
        );

        return $token;
    }

    protected function editorScheme(string $file, int $line): string
    {
        $editor = config('pinpoint.editor', 'vscode');

        return match ($editor) {
            'phpstorm' => sprintf('phpstorm://open?file=%s&line=%d', rawurlencode($file), $line),
            // Path separators must stay literal — VSCode's URI handler cannot
            // resolve %2F-encoded slashes (only other chars may be encoded).
            default => sprintf('vscode://file/%s:%d', str_replace('%2F', '/', rawurlencode($file)), $line),
        };
    }

    /**
     * @param  array<int, array{route: string, p95: int, avg: int, samples: int, tier: string, n1: string}>  $rows
     */
    public function reportTable(string $title, array $rows, ?string $emptyMessage = null): void
    {
        if ($rows === []) {
            if ($emptyMessage) {
                $this->render(sprintf('<div class="mx-2 my-1 text-gray-500">%s</div>', e($emptyMessage)));
            }

            return;
        }

        // Scannable summary first — the actionable numbers land before the
        // table, same convention as Horizon/Octane/Pest output.
        $critical = count(array_filter($rows, fn ($r) => $r['tier'] === TierClassifier::CRITICAL));
        $n1 = count(array_filter($rows, fn ($r) => str_starts_with($r['n1'], 'Yes')));

        $html = $this->header($title)
            .sprintf('<div class="mt-1 mb-1 text-gray-400">%d route(s) · <span class="text-white">%d</span> critical · <span class="text-white">%d</span> with N+1</div>', count($rows), $critical, $n1);

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
                .'<td class="text-left text-white">'.e($this->routeLabel($row['route'])).'</td>'
                .'<td class="text-right text-white">'.$row['p95'].'<span class="text-gray-500">ms</span></td>'
                .'<td class="text-right text-white">'.$row['avg'].'<span class="text-gray-500">ms</span></td>'
                .'<td class="text-right text-gray-300">'.$row['samples'].'</td>'
                .'<td class="text-left">'.$this->tierBadge($row['tier']).'</td>'
                .'<td class="text-center">'.$this->n1Badge($row['n1']).'</td>'
                .'</tr>';
        }

        $html .= '</tbody></table>';

        $this->render($html);
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

        $this->render($html);
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
                ? ' at '.$this->callerLink($chain['caller_file'], $chain['caller_line'])
                : '';

            $html .= '<div class="mt-1 text-white">'.e($chain['model']).' -> '.e($chain['relations']).$caller.'</div>';
            $html .= '<div class="text-gray-400">Suggested fix: <span class="text-green-400">'.e($chain['model']).'::with('.e(var_export($chain['relations'], true)).')</span></div>';
        }

        $html .= '</div>';

        $this->render($html);
    }

    public function info(string $message): void
    {
        $this->render('<div class="mx-2 my-1 text-gray-300">'.e($message).'</div>');
    }

    /**
     * Compact "locate" block printed under the summary table for the worst
     * offenders (N+1 or critical routes). Capped so a long list of bad
     * routes can't flood the terminal.
     *
     * @param  array<int, array{route: string, reason: string, repeat: int, caller_file: string|null, caller_line: int|null}>  $offenders
     */
    public function locate(array $offenders, int $cap = 5): void
    {
        if ($offenders === []) {
            return;
        }

        usort($offenders, fn ($a, $b) => $b['repeat'] <=> $a['repeat']);

        $html = $this->header('Locate');

        $shown = array_slice($offenders, 0, $cap);

        foreach ($shown as $offender) {
            $caller = $offender['caller_file']
                ? $this->callerLink($offender['caller_file'], $offender['caller_line'])
                : '<span class="text-gray-600">run --route for caller</span>';

            $html .= '<div class="mt-1">'
                .'<span class="text-white">'.e($offender['route']).'</span> '
                .'<span class="text-gray-400">— '.e($offender['reason']).'</span>'
                .'<div class="text-gray-400">'.$caller.'</div>'
                .'</div>';
        }

        if (count($offenders) > $cap) {
            $html .= '<div class="mt-1 text-gray-500">'.(count($offenders) - $cap).' more route(s) — run <span class="text-blue-400">pinpoint:report --route=&lt;name&gt;</span> for exact file and line.</div>';
        }

        $html .= '</div>';

        $this->render($html);
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
                ? $this->callerLink($query->caller_file, $query->caller_line)
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

        $this->render($html);
    }

    /**
     * Cap route labels so long names can't push the p95/avg columns off
     * narrow terminals (Termwind sizes columns to content).
     */
    protected function routeLabel(string $route, int $max = 40): string
    {
        return mb_strimwidth($route, 0, $max, '…');
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
            TierClassifier::GOOD => '<span class="px-1 bg-green-600 text-white font-bold">'.$label.'</span>',
            TierClassifier::ACCEPTABLE => '<span class="px-1 bg-yellow-600 text-black font-bold">'.$label.'</span>',
            TierClassifier::NEEDS_IMPROVEMENT => '<span class="px-1 bg-orange-600 text-white font-bold">'.$label.'</span>',
            TierClassifier::CRITICAL => '<span class="px-1 bg-red-600 text-white font-bold">'.$label.'</span>',
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
