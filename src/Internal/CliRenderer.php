<?php

namespace AsimAli\Pinpoint\Internal;

use AsimAli\Pinpoint\TierClassifier;
use Illuminate\Console\OutputStyle;
use Illuminate\Http\Request;
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
    /** @var array<string, string> token => Symfony <href=...> tag */
    protected array $hyperlinks = [];

    protected function render(string $html): void
    {
        // Termwind's Laravel provider wires render() to the running command's
        // OutputStyle, so output stays testable and verbosity-aware.
        //
        // Parse first, then swap hyperlink tokens: Termwind's own render()
        // strips ANY angle-bracket markup during its HTML parse phase —
        // including <a href> and raw OSC 8 bytes — so links are injected as
        // Symfony <href=URI>text</> tags afterwards, and the decorated
        // OutputFormatter turns them into canonical OSC 8 sequences.
        $rendered = parse($html);

        // Termwind::getRenderer() is NOT public API — the whole Termwind class
        // is marked @internal in its source (nunomaduro/termwind, src/Termwind.php).
        // It's used because render()/parse() strip <a href> / raw OSC 8 bytes
        // during their HTML parse phase, so links can only be injected after
        // parsing. Revisit if Termwind ever adds native OSC 8 support; spec:
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
     * Replace hyperlink tokens (inserted by callerLink) with Symfony
     * <href=URI>text</> tags AFTER Termwind has rendered. The decorated
     * OutputFormatter converts them into real OSC 8 terminal sequences.
     */
    protected function replaceHyperlinks(string $html): string
    {
        foreach ($this->hyperlinks as $token => $hrefTag) {
            $html = str_replace($token, $hrefTag, $html);
        }

        return $html;
    }

    /**
     * Render a caller as a clickable OSC 8 terminal hyperlink.
     *
     * Never spawns a shell command — the host terminal resolves the URI
     * scheme, so it works from inside Docker/Sail/WSL.
     *
     * The URI uses the ABSOLUTE path (VS Code falls back to workspace search
     * for relative paths); the label shows the workspace-relative path.
     */
    protected function callerLink(?string $file, ?int $line): string
    {
        // Without a line number the target would be "file:0" — no link is
        // better than a link that jumps nowhere.
        if (! $file || $line === null) {
            return '<span class="text-gray-600">-</span>';
        }

        $label = e($this->relativeCaller($file).':'.$line);
        $token = $this->linkToken($label);

        if ($token === null) {
            return $label;
        }

        $this->hyperlinks[$token] = sprintf('<href=%s>%s</>', $this->editorScheme($file, $line), $label);

        return $token;
    }

    /**
     * Unique placeholder token whose DISPLAY WIDTH equals the visible label.
     *
     * Termwind sizes table columns from the raw cell text — a 19-char
     * "__PINPOINT_LINK_n__" token would make columns 19 wide around a 5-char
     * label, breaking the table geometry (text overlapping borders). A
     * same-width token keeps columns correct, then the swap replaces it with
     * the same-width visible text.
     *
     * Returns null when the label is too short to embed a unique index —
     * the caller then falls back to a plain label (no link).
     */
    protected function linkToken(string $label): ?string
    {
        $width = mb_strlen($label);

        if ($width < 9) {
            return null;
        }

        $token = '__PP_L_'.count($this->hyperlinks).'_';

        while (mb_strlen($token) < $width) {
            $token .= '_';
        }

        return mb_substr($token, 0, $width);
    }

    protected function editorScheme(string $file, int $line): string
    {
        $editor = config('pinpoint.editor', 'vscode');

        $absolutePath = $this->absolutePath($file);

        return match ($editor) {
            'phpstorm' => sprintf('phpstorm://open?file=%s&line=%d', rawurlencode($absolutePath), $line),
            // VS Code-compatible scheme (also registered by Cursor,
            // Windsurf/Devin Desktop — the URI handler is the editor's).
            // Canonical form is EXACTLY one slash between "file" and the
            // path: vscode://file{path}:{line}. A double slash (vscode://file//mnt/...)
            // makes the URL parser see an empty path, handlers misbehave
            // (wrong/recent-focus file opens instead of the target).
            default => sprintf('%s://file/%s:%d', $editor, ltrim(str_replace('%2F', '/', rawurlencode($absolutePath)), '/'), $line),
        };
    }

    protected function absolutePath(string $file): string
    {
        // Caller paths from Caller::capture() are stored workspace-relative
        // (base_path()-stripped), but URI handlers need absolute paths —
        // a relative vscode://file path makes VS Code fall back to search.
        if (str_starts_with($file, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $file)) {
            return $file;
        }

        $base = base_path();

        return str_starts_with($file, $base) ? $file : $base.DIRECTORY_SEPARATOR.$file;
    }

    protected function relativeCaller(string $file): string
    {
        $base = base_path();

        if (str_starts_with($file, $base)) {
            return ltrim(substr($file, strlen($base)), '/\\');
        }

        return $file;
    }

    public function routeLink(string $route, int $max = 40): string
    {
        $label = e($this->routeLabel($route, $max));
        $location = $this->routeActionLocation($route);

        if ($location && $location['file'] && $location['line'] !== null) {
            $token = $this->linkToken($label);

            if ($token !== null) {
                $this->hyperlinks[$token] = sprintf('<href=%s>%s</>', $this->editorScheme($location['file'], $location['line']), $label);

                return $token;
            }
        }

        return $label;
    }

    public function routeActionLocation(string $routeLabel): ?array
    {
        if (! function_exists('app') || ! app()->bound('router')) {
            return null;
        }

        try {
            $router = app('router');
            $routes = $router->getRoutes();

            // Name lookups are only refreshed when routes are loaded through
            // the service provider — after runtime registration (tests) they
            // can be stale. Rebuilding the map is cheap for a dev CLI.
            $routes->refreshNameLookups();

            $route = $routes->getByName($routeLabel);

            if (! $route && str_contains($routeLabel, ' ')) {
                [$method, $path] = explode(' ', $routeLabel, 2);
                try {
                    $route = $routes->match(Request::create($path, $method));
                } catch (\Throwable) {
                    $route = null;
                }
            }

            if (! $route) {
                return null;
            }

            $uses = $route->getAction('uses');

            if ($uses instanceof \Closure) {
                $ref = new \ReflectionFunction($uses);

                return ['file' => $ref->getFileName(), 'line' => (int) $ref->getStartLine()];
            }

            if (is_string($uses) && str_contains($uses, '@')) {
                [$class, $method] = explode('@', $uses, 2);
                if (class_exists($class) && method_exists($class, $method)) {
                    $ref = new \ReflectionMethod($class, $method);

                    return ['file' => $ref->getFileName(), 'line' => (int) $ref->getStartLine()];
                }
            }

            // Invokable controller: Route::get('/x', InvokableController::class)
            // → action 'uses' is the bare class string → __invoke.
            if (is_string($uses) && ! str_contains($uses, '@') && class_exists($uses) && method_exists($uses, '__invoke')) {
                $ref = new \ReflectionMethod($uses, '__invoke');

                return ['file' => $ref->getFileName(), 'line' => (int) $ref->getStartLine()];
            }

            if (is_array($uses) && count($uses) === 2 && is_string($uses[0]) && is_string($uses[1])) {
                if (class_exists($uses[0]) && method_exists($uses[0], $uses[1])) {
                    $ref = new \ReflectionMethod($uses[0], $uses[1]);

                    return ['file' => $ref->getFileName(), 'line' => (int) $ref->getStartLine()];
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<int, array{route: string, p95: int, avg: int, samples: int, tier: string, n1: string, memory?: string|null, memory_over_budget?: bool, has_duplicate?: bool}>  $rows
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
        $duplicate = count(array_filter($rows, fn ($r) => (bool) ($r['has_duplicate'] ?? false)));

        $html = $this->header($title)
            .sprintf(
                '<div class="mt-1 mb-1 text-gray-400">%d route(s) · <span class="text-white">%d</span> critical · <span class="text-white">%d</span> with N+1 · <span class="text-white">%d</span> with duplicate queries</div>',
                count($rows), $critical, $n1, $duplicate
            );

        $composite = (bool) config('pinpoint.composite_tier', false);

        $tierHeader = $composite
            ? 'Health (tier + N+1 + memory)'
            : 'Tier (p95 only)';

        $html .= '<table class="w-full"><thead><tr class="text-gray-500 border-b border-gray-600">'
            .'<th class="text-left">Route</th>'
            .'<th class="text-right">p95</th>'
            .'<th class="text-right">Avg</th>'
            .'<th class="text-right">Samples</th>'
            .'<th class="text-right">Memory (peak)</th>'
            .'<th class="text-left">'.$tierHeader.'</th>'
            .'<th class="text-center">N+1?</th>'
            .'</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>'
                .'<td class="text-left text-white">'.$this->routeLink($row['route']).'</td>'
                .'<td class="text-right text-white">'.$row['p95'].'<span class="text-gray-500">ms</span></td>'
                .'<td class="text-right text-white">'.$row['avg'].'<span class="text-gray-500">ms</span></td>'
                .'<td class="text-right text-gray-300">'.$row['samples'].'</td>'
                .'<td class="text-right">'.$this->memoryCell($row['memory'] ?? null, (bool) ($row['memory_over_budget'] ?? false)).'</td>'
                .'<td class="text-left">'.$this->healthOrTierBadge($row, $composite).'</td>'
                .'<td class="text-center">'.$this->n1Badge($row['n1']).'</td>'
                .'</tr>';
        }

        $html .= '</tbody></table></div>';

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

        $html .= '</div>';

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

    /**
     * @param  Collection<int, \stdClass>  $queries
     */
    public function duplicateQuerySuggestions(Collection $queries): void
    {
        $duplicates = $queries->filter(fn ($q) => ($q->query_type ?? null) === 'duplicate');

        if ($duplicates->isEmpty()) {
            return;
        }

        $html = '<div class="mx-2 my-1">'
            .'<div class="mt-2"><span class="px-1 bg-cyan-600 text-white font-bold">Duplicate queries detected</span></div>';

        foreach ($duplicates as $query) {
            $caller = $query->caller_file
                ? ' at '.$this->callerLink($query->caller_file, $query->caller_line)
                : '';

            $html .= '<div class="mt-1 text-white">'.e(str_replace("\n", ' ', mb_strimwidth($query->sql, 0, 70, '…'))).' (x'.$query->repeat_count.')'.$caller.'</div>';
            $html .= '<div class="text-gray-400">Suggested fix: <span class="text-cyan-400">Cache::remember(...)</span> or memoize query result in memory</div>';
        }

        $html .= '</div>';

        $this->render($html);
    }

    /**
     * @param  Collection<int, \stdClass>  $queries
     */
    public function n1QuerySuggestions(Collection $queries): void
    {
        $n1s = $queries->filter(fn ($q) => ($q->query_type ?? null) === 'n_plus_one');

        if ($n1s->isEmpty()) {
            return;
        }

        $html = '<div class="mx-2 my-1">'
            .'<div class="mt-2"><span class="px-1 bg-red-600 text-white font-bold">N+1 query pattern detected</span></div>';

        foreach ($n1s as $query) {
            $caller = $query->caller_file
                ? ' at '.$this->callerLink($query->caller_file, $query->caller_line)
                : '';

            $html .= '<div class="mt-1 text-white">'.e(str_replace("\n", ' ', mb_strimwidth($query->sql, 0, 70, '…'))).' (x'.$query->repeat_count.')'.$caller.'</div>';
            $html .= '<div class="text-gray-400">Suggested fix: Eager load with <span class="text-green-400">Model::with(...)</span></div>';
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
                .'<span class="text-white">'.$this->routeLink($offender['route']).'</span> '
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
            .'<th class="text-left">Type</th>'
            .'<th class="text-left">Caller</th>'
            .'</tr></thead><tbody>';

        foreach ($queries as $query) {
            $isN1 = $query->repeat_count >= $n1Threshold;
            $caller = $query->caller_file
                ? $this->callerLink($query->caller_file, $query->caller_line)
                : '<span class="text-gray-600">-</span>';

            // query_type is set by QueryReader::topQueries() for repeated groups.
            $typeBadge = $isN1
                ? $this->queryTypeBadge($query->query_type ?? null, $query->repeat_count)
                : '<span class="text-gray-600">-</span>';

            $html .= '<tr>'
                .'<td class="text-left text-white">'.e(str_replace("\n", ' ', mb_strimwidth($query->sql, 0, 60, '…'))).'</td>'
                .'<td class="text-right">'.($isN1 ? '<span class="text-red-500 font-bold">'.$query->repeat_count.'</span>' : '<span class="text-gray-300">'.$query->repeat_count.'</span>').'</td>'
                .'<td class="text-right text-white">'.(int) round($query->avg_ms).'</td>'
                .'<td class="text-right text-white">'.$query->max_ms.'</td>'
                .'<td class="text-left">'.$typeBadge.'</td>'
                .'<td class="text-left">'.$caller.'</td>'
                .'</tr>';
        }

        $html .= '</tbody></table></div>';

        $this->render($html);
    }

    protected function header(string $title): string
    {
        return '<div class="mx-2 my-1">'
            .'<div class="flex justify-between w-full mb-1">'
            .'<span class="px-2 bg-blue-500 text-white font-bold uppercase">Pinpoint</span>'
            .'<span class="text-gray-400">'.e($title).'</span>'
            .'</div>';
    }

    /**
     * Cap route labels so long names can't push the p95/avg columns off
     * narrow terminals (Termwind sizes columns to content).
     */
    protected function routeLabel(string $route, int $max = 40): string
    {
        return mb_strimwidth($route, 0, $max, '…');
    }

    /**
     * Pick the badge for the tier column: composite Health verdict when
     * enabled, plain p95 tier otherwise.
     *
     * @param  array{tier: string, n1: string, memory_over_budget: bool}  $row
     */
    protected function healthOrTierBadge(array $row, bool $composite): string
    {
        return $composite
            ? $this->healthBadge($row)
            : $this->tierBadge($row['tier']);
    }

    /**
     * Composite health verdict (opt-in via pinpoint.composite_tier):
     * HEALTHY only when the p95 tier is good/acceptable AND no N+1 AND
     * memory is within budget. The p95 tier rides along in parentheses so
     * latency context is never lost — e.g. "NEEDS WORK (GOOD)" means fast,
     * but flagged for another reason.
     *
     * @param  array{tier: string, n1: string, memory_over_budget: bool}  $row
     */
    protected function healthBadge(array $row): string
    {
        $tierLabel = strtoupper($row['tier']);

        $isHealthyTier = in_array($row['tier'], [TierClassifier::GOOD, TierClassifier::ACCEPTABLE], true);
        $hasN1 = str_starts_with($row['n1'], 'Yes');
        $overMemory = $row['memory_over_budget'];

        if ($isHealthyTier && ! $hasN1 && ! $overMemory) {
            return '<span class="px-1 bg-green-600 text-white font-bold">HEALTHY</span>';
        }

        return '<span><span class="px-1 bg-red-600 text-white font-bold">NEEDS WORK</span>'
            .'<span class="text-gray-600"> ('.$tierLabel.')</span></span>';
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

    /**
     * Badge for the query type column in the drill-in query table.
     *
     * Visual language:
     *   [N+1]     red    — fix with Model::with() (varying bindings per loop)
     *   [CACHE]   cyan   — fix with Cache::remember() (identical query repeated)
     *   [REPEAT]  yellow — classification unknown (null bindings_hash recorded)
     */
    protected function queryTypeBadge(?string $type, int $count): string
    {
        return match ($type) {
            'duplicate' => '<span class="px-1 bg-cyan-600 text-white font-bold">CACHE x'.$count.'</span>',
            'n_plus_one' => '<span class="px-1 bg-red-600 text-white font-bold">N+1 x'.$count.'</span>',
            // Bindings were not captured (e.g. raw DB::statement) — we know
            // it repeats but cannot tell if the values differ.
            default => '<span class="px-1 bg-yellow-600 text-black font-bold">REPEAT x'.$count.'</span>',
        };
    }

    /**
     * Render a memory figure for the report table.
     *
     * Returns an em-dash when no data is available (pre-migration rows, routes
     * not yet accessed since the upgrade). Returns a red-highlighted value when
     * peak RAM exceeds the configured budget.
     */
    protected function memoryCell(?string $formatted, bool $overBudget): string
    {
        if ($formatted === null) {
            return '<span class="text-gray-600">—</span>';
        }

        return $overBudget
            ? '<span class="text-red-500 font-bold">'.$formatted.'</span>'
            : '<span class="text-gray-300">'.$formatted.'</span>';
    }
}
