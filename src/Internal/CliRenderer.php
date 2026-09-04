<?php

namespace AsimAli\Pinpoint\Internal;

use AsimAli\Pinpoint\TierClassifier;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use ReflectionFunction;
use ReflectionMethod;
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
        // Termwind strips raw HTML tags and terminal escape sequences during parsing.
        // Hyperlink placeholder tokens are resolved post-parse into Symfony <href> tags.
        $rendered = parse($html);

        // Inject post-parsed OSC 8 hyperlink escape sequences via Termwind's internal renderer.
        Termwind::getRenderer()->writeln($this->replaceHyperlinks($rendered));

        // Reset tokens after output generation to prevent unbounded memory growth under Octane.
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
        // Omit hyperlink when line number is missing.
        if (! $file || $line === null) {
            return '<span class="text-gray-600">-</span>';
        }

        $label = e(EditorLink::relativeCaller($file).':'.$line);
        $token = $this->linkToken($label);

        if ($token === null) {
            return $label;
        }

        $this->hyperlinks[$token] = sprintf('<href=%s>%s</>', EditorLink::scheme($file, $line), $label);

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

    public function routeLink(string $route, int $max = 40): string
    {
        $label = e(mb_strimwidth($route, 0, $max, '…'));
        $location = $this->routeActionLocation($route);

        $linkable = $location && $location['file'] && $location['line'] !== null;

        // Split "METHOD path" labels route:list-style: cyan verb + linked
        // URI, nested in one span (Termwind drops sibling spans in cells).
        // Named routes (no verb) render exactly as before.
        if (! str_contains($label, ' ')) {
            if (! $linkable) {
                return $label;
            }

            $token = $this->linkToken($label);

            if ($token === null) {
                return $label;
            }

            $this->hyperlinks[$token] = sprintf('<href=%s>%s</>', EditorLink::scheme($location['file'], $location['line']), $label);

            return $token;
        }

        [$method, $uri] = explode(' ', $label, 2);
        // Route:list-style leading slash. The token stands in for the
        // displayed (slashed) URI so column widths hold after the swap —
        // never emit a literal slash next to a token.
        $displayUri = '/'.ltrim($uri, '/');

        if ($linkable) {
            $token = $this->linkToken($displayUri);

            if ($token !== null) {
                $this->hyperlinks[$token] = sprintf('<href=%s>%s</>', EditorLink::scheme($location['file'], $location['line']), $displayUri);

                return '<span><span class="text-cyan-400 font-bold">'.$method.'</span> '.$token.'</span>';
            }

            // Short labels can't host a URI-width token — link the whole
            // label exactly as before so no route loses its hyperlink.
            $token = $this->linkToken($label);

            if ($token !== null) {
                $this->hyperlinks[$token] = sprintf('<href=%s>%s</>', EditorLink::scheme($location['file'], $location['line']), $label);

                return $token;
            }
        }

        return '<span><span class="text-cyan-400 font-bold">'.$method.'</span> '.$displayUri.'</span>';
    }

    public function routeActionLocation(string $routeLabel): ?array
    {
        if (! function_exists('app') || ! app()->bound('router')) {
            return null;
        }

        try {
            $route = $this->findRoute($routeLabel);

            return $route ? $this->resolveCallableLocation($route->getAction('uses')) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function findRoute(string $routeLabel): ?Route
    {
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

        return $route;
    }

    protected function resolveCallableLocation(mixed $uses): ?array
    {
        if ($uses instanceof \Closure) {
            $ref = new ReflectionFunction($uses);

            return ['file' => $ref->getFileName(), 'line' => (int) $ref->getStartLine()];
        }

        if (is_string($uses)) {
            return $this->resolveClassStringLocation($uses);
        }

        return $this->resolveArrayCallableLocation($uses);
    }

    protected function resolveArrayCallableLocation(mixed $uses): ?array
    {
        if (is_array($uses) && count($uses) === 2 && is_string($uses[0]) && is_string($uses[1]) && class_exists($uses[0]) && method_exists($uses[0], $uses[1])) {
            $ref = new ReflectionMethod($uses[0], $uses[1]);

            return ['file' => $ref->getFileName(), 'line' => (int) $ref->getStartLine()];
        }

        return null;
    }

    protected function resolveClassStringLocation(string $uses): ?array
    {
        if (str_contains($uses, '@')) {
            [$class, $method] = explode('@', $uses, 2);
            if (class_exists($class) && method_exists($class, $method)) {
                $ref = new ReflectionMethod($class, $method);

                return ['file' => $ref->getFileName(), 'line' => (int) $ref->getStartLine()];
            }
        } elseif (class_exists($uses) && method_exists($uses, '__invoke')) {
            $ref = new ReflectionMethod($uses, '__invoke');

            return ['file' => $ref->getFileName(), 'line' => (int) $ref->getStartLine()];
        }

        return null;
    }

    /**
     * @param  array<int, array{route: string, p95: int, avg: int, samples: int, tier: string, n1: string, memory?: string|null, memory_over_budget?: bool, has_duplicate?: bool, health?: string|null}>  $rows
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

        $html = BadgeRenderer::header($title)
                    .sprintf(
                        '<div class="mt-1 mb-1 text-gray-400">%d route(s) · <span class="text-red-500">●</span> <span class="text-white">%d</span> critical · <span class="text-yellow-500">▲</span> <span class="text-white">%d</span> with N+1 · <span class="text-cyan-400">◆</span> <span class="text-white">%d</span> with duplicate queries</div>',
                        count($rows),
                        $critical,
                        $n1,
                        $duplicate
                    );

        $composite = (bool) config('pinpoint.composite_tier', false);

        $tierHeader = $composite
            ? 'Health'
            : 'Tier (p95 only)';

        $html .= '<hr>'
            .'<table class="w-full" style="compact"><thead><tr class="text-gray-500 border-b border-gray-600">'
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
                .'<td class="text-right text-white"><span>'.$row['p95'].'<span class="text-gray-500">ms</span></span></td>'
                .'<td class="text-right text-white"><span>'.$row['avg'].'<span class="text-gray-500">ms</span></span></td>'
                .'<td class="text-right text-gray-300">'.$row['samples'].'</td>'
                .'<td class="text-right">'.BadgeRenderer::memoryCell($row['memory'] ?? null, (bool) ($row['memory_over_budget'] ?? false)).'</td>'
                .'<td class="text-left">'.BadgeRenderer::healthOrTier($row, $composite).'</td>'
                .'<td class="text-center">'.BadgeRenderer::n1($row['n1']).'</td>'
                .'</tr>';
        }

        $html .= '</tbody></table><hr></div>';

        $this->render($html);
    }

    /**
     * @param  array<int, array{type: string, route: string, detail: string}>  $violations
     */
    public function checkReport(array $violations, int $checked, int $windowMinutes, bool $passed): void
    {
        $html = BadgeRenderer::header('Pinpoint Check');

        if ($passed) {
            $html .= '<div class="mt-1 text-green-400 font-bold">All checks passed.</div>';
        }

        foreach ($violations as $violation) {
            $badge = match ($violation['type']) {
                'n_plus_one' => '<span class="px-1 bg-red-600 text-white font-bold">N+1</span>',
                'duplicate' => '<span class="px-1 bg-cyan-600 text-white font-bold">CACHE</span>',
                'unknown' => '<span class="px-1 bg-yellow-600 text-black font-bold">REPEAT</span>',
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
     * Offenders are grouped by finding type (N+1, duplicates, slow tier)
     * with tree linkage from each route to its caller — no separate
     * brand header, so the block reads as a continuation of the report.
     *
     * @param  array<int, array{route: string, reason: string, repeat: int, caller_file: string|null, caller_line: int|null}>  $offenders
     */
    public function locate(array $offenders, int $cap = 5): void
    {
        if ($offenders === []) {
            return;
        }

        usort($offenders, fn ($a, $b) => $b['repeat'] <=> $a['repeat']);

        $groups = [
            ['match' => 'N+1', 'title' => '▲ N+1 queries', 'fix' => 'Fix: eager-load with Model::with(...)'],
            ['match' => 'CACHE', 'title' => '◆ Duplicate queries', 'fix' => 'Fix: wrap the repeated query in Cache::remember(...)'],
            ['match' => 'REPEAT', 'title' => '▲ Repeated queries', 'fix' => null],
            ['match' => null, 'title' => '● Critical tier', 'fix' => null],
        ];

        $html = '<div class="mx-2 my-1"><div class="mt-2 text-gray-500">Locate</div>';

        foreach ($groups as $group) {
            // Group from ALL offenders — never a global slice, so every
            // route the banner counts stays present under its group.
            $items = array_values(array_filter(
                $offenders,
                fn ($o) => $group['match'] === null
                    ? ! str_starts_with($o['reason'], 'N+1') && ! str_starts_with($o['reason'], 'CACHE') && ! str_starts_with($o['reason'], 'REPEAT')
                    : str_starts_with($o['reason'], $group['match'])
            ));

            if ($items === []) {
                continue;
            }

            $total = count($items);

            $html .= '<div class="mt-1 text-white">'.$group['title'].' · '.$total.($total === 1 ? ' route' : ' routes').'</div>';

            if ($group['fix'] !== null) {
                $html .= '<div class="text-gray-500">'.$group['fix'].'</div>';
            }

            foreach (array_slice($items, 0, $cap) as $i => $offender) {
                $last = $i === min($cap, $total) - 1;
                $branch = $last ? '└── ' : '├── ';
                $stem = $last ? '    ' : '│   ';
                $caller = $offender['caller_file']
                    ? $this->callerLink($offender['caller_file'], $offender['caller_line'])
                    : '<span class="text-gray-600">run --route for caller</span>';

                $html .= '<div class="mt-1">'
                    .'<span class="text-white">'.$branch.$this->routeLink($offender['route']).'</span> '
                    .'<span class="text-gray-400">— '.e($offender['reason']).'</span>'
                    .'<div class="text-gray-400">'.$stem.'└── '.$caller.'</div>'
                    .'</div>';
            }

            if ($total > $cap) {
                $html .= '<div class="mt-1 text-gray-500">… and '.($total - $cap).' more — run <span class="text-blue-400">pinpoint:report --route=&lt;name&gt;</span> for exact file and line.</div>';
            }
        }

        $html .= '</div>';

        $this->render($html);
    }

    /**
     * @param  Collection<int, \stdClass>  $queries
     */
    public function queriesTable($queries, int $n1Threshold): void
    {
        $html = BadgeRenderer::header('Top Offending Queries');

        $html .= '<hr>'
            .'<table class="w-full" style="compact"><thead><tr class="text-gray-500 border-b border-gray-600">'
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
                ? BadgeRenderer::queryType($query->query_type ?? null, $query->repeat_count)
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

        $html .= '</tbody></table><hr></div>';

        $this->render($html);
    }
}
