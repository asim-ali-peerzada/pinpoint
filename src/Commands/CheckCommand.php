<?php

namespace AsimAli\Pinpoint\Commands;

use AsimAli\Pinpoint\Commands\Concerns\EmitsJson;
use AsimAli\Pinpoint\Internal\CliRenderer;
use AsimAli\Pinpoint\Internal\SinceParser;
use AsimAli\Pinpoint\Internal\SuggestionBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class CheckCommand extends Command
{
    use EmitsJson;

    protected $signature = 'pinpoint:check
        {--fail-on-n1 : Fail on N+1 patterns (excludes exact duplicates — see --fail-on-duplicates)}
        {--fail-on-duplicates : Fail on exact-duplicate queries (identical bindings)}
        {--max-queries= : Fail when any request exceeds this query count}
        {--max-duration-ms= : Fail when any request exceeds this duration in ms}
        {--since=30 : Only check requests from the last N (e.g. 5m, 1h, 2d; bare number = minutes)}
        {--allow-empty : Pass when the window contains no requests instead of failing}
        {--json : Output machine-readable JSON on stdout (for CI / PR comment automation)}
        {--json-to= : Write the JSON output to a file and print the file location (human-friendly alternative; implies --json)}
        {--limit=20 : Max violations to report}';

    protected $description = 'CI gate: fail when recorded requests violate N+1 or performance budgets';

    public function __construct(
        protected SuggestionBuilder $suggestions,
        protected CliRenderer $cli,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            return $this->check();
        } catch (Throwable $e) {
            Log::error('Pinpoint: check failed', ['exception' => $e->getMessage()]);
            $this->error('Pinpoint check failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    protected function check(): int
    {
        try {
            $sinceMinutes = SinceParser::toMinutes((string) $this->option('since'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $maxQueries = $this->option('max-queries') !== null ? (int) $this->option('max-queries') : null;
        $maxDurationMs = $this->option('max-duration-ms') !== null ? (int) $this->option('max-duration-ms') : null;
        $failOnN1 = (bool) $this->option('fail-on-n1');
        $failOnDuplicates = (bool) $this->option('fail-on-duplicates');
        $limit = (int) $this->option('limit');

        $cutoff = now()->subMinutes($sinceMinutes);

        $requests = DB::table('pinpoint_requests')
            ->where('created_at', '>=', $cutoff)
            ->orderBy('duration_ms', 'desc')
            ->get();

        $violations = [];

        if ($requests->isEmpty()) {
            // Fail closed: a gate that checked nothing is a false green (e.g.
            // the suite ran outside --since, or recording was off). --allow-empty
            // is the explicit opt-out for legitimately empty windows.
            $message = sprintf(
                'No requests recorded in the last %d minute(s) — the gate checked nothing. Widen --since, verify Pinpoint recording is on, or pass --allow-empty.',
                $sinceMinutes
            );

            if ($this->option('allow-empty')) {
                $this->warn(sprintf('No requests recorded in the last %d minute(s) — nothing to check (--allow-empty).', $sinceMinutes));
                $this->printResult([], ['requests' => 0, 'window_minutes' => $sinceMinutes, 'empty' => true], $limit);

                return self::SUCCESS;
            }

            if ($this->option('json') || $this->option('json-to')) {
                // Pure JSON on stdout — no interleaved error text.
                $this->emitJson([
                    'passed' => false,
                    'meta' => ['requests' => 0, 'window_minutes' => $sinceMinutes, 'empty' => true],
                    'violations' => [],
                    'message' => $message,
                ]);
            } else {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $n1Threshold = (int) config('pinpoint.n_plus_one_repeat_threshold', 3);

        if ($failOnN1 || $failOnDuplicates) {
            $violations = array_merge($violations, $this->n1Violations($requests, $n1Threshold, $failOnN1, $failOnDuplicates));
        }

        if ($maxQueries !== null) {
            foreach ($requests as $request) {
                if ($request->query_count > $maxQueries) {
                    $violations[] = [
                        'type' => 'query_budget',
                        'route' => $this->label($request),
                        'request_id' => $request->id,
                        'query_count' => $request->query_count,
                        'budget' => $maxQueries,
                    ];
                }
            }
        }

        if ($maxDurationMs !== null) {
            foreach ($requests as $request) {
                if ($request->duration_ms > $maxDurationMs) {
                    $violations[] = [
                        'type' => 'duration_budget',
                        'route' => $this->label($request),
                        'request_id' => $request->id,
                        'duration_ms' => $request->duration_ms,
                        'budget_ms' => $maxDurationMs,
                    ];
                }
            }
        }

        if ((float) config('pinpoint.sample_rate', 1.0) < 1.0) {
            $this->warn(sprintf('pinpoint.sample_rate is %.1f — results may miss violations on unsampled requests.', (float) config('pinpoint.sample_rate', 1.0)));
        }

        $this->printResult($violations, ['requests' => count($requests), 'window_minutes' => $sinceMinutes], $limit);

        return $violations === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Repeat-pattern violations, filtered by the enabled gates.
     *
     * --fail-on-n1 covers true N+1 (varying bindings, lazy loads) plus
     * unclassifiable repeats (no binding data — cannot be proven safe).
     * Proven duplicates fail only under --fail-on-duplicates: the flag
     * must mean what it says, and duplicates are cache candidates, not N+1.
     *
     * Detection is deliberately untruncated: the --limit only caps display
     * (printResult slices), so a gate can never false-green because the
     * offending group sat below a top-N cutoff.
     *
     * @param  Collection<int, \stdClass>  $requests
     */
    protected function n1Violations($requests, int $threshold, bool $includeN1, bool $includeDuplicates): array
    {
        $ids = $requests->pluck('id');

        $groups = DB::table('pinpoint_queries')
            ->whereIn('request_id', $ids)
            ->select('request_id', 'sql', 'caller_file', 'caller_line')
            ->selectRaw('COUNT(*) as repeat_count')
            ->selectRaw('COUNT(DISTINCT bindings_hash) as distinct_binding_count')
            ->selectRaw('SUM(CASE WHEN bindings_hash IS NULL THEN 1 ELSE 0 END) as null_binding_count')
            ->groupBy('request_id', 'sql', 'caller_file', 'caller_line')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->orderByDesc('repeat_count')
            ->get();

        $requestsById = $requests->keyBy('id');

        $violations = $groups
            ->map(function ($group) use ($requestsById) {
                $request = $requestsById[$group->request_id];

                // Same classification as QueryReader: identical bindings mean
                // a cache candidate, not an N+1 — label it correctly so the
                // suggested fix is Cache::remember(), not with().
                $type = $group->null_binding_count > 0
                    ? 'unknown'
                    : ($group->distinct_binding_count == 1 ? 'duplicate' : 'n_plus_one');

                return [
                    'type' => $type,
                    'route' => $this->label($request),
                    'request_id' => $group->request_id,
                    'sql' => $group->sql,
                    'repeat_count' => $group->repeat_count,
                    'caller_file' => $group->caller_file,
                    'caller_line' => $group->caller_line,
                ];
            })
            ->filter(fn ($violation) => match ($violation['type']) {
                'n_plus_one' => $includeN1,
                'duplicate' => $includeDuplicates,
                // Unclassifiable repeats fail the N+1 gate (cannot be proven
                // safe) but never the duplicates-only gate.
                default => $includeN1,
            })
            ->values()
            ->all();

        // Eloquent lazy-load violations are the primary N+1 signal — the
        // request is flagged has_n_plus_one even when the repeat count is
        // below the fingerprint threshold (e.g. 2 lazy loads, threshold 3).
        // They belong to the N+1 gate only, never the duplicates gate.
        // The flag alone is not enough: it is also set by duplicate
        // repeats, so require actual lazy-load rows.
        if ($includeN1) {
            $lazyRequestIds = DB::table('pinpoint_lazy_loads')
                ->whereIn('request_id', $ids)
                ->distinct()
                ->pluck('request_id')
                ->all();

            foreach ($requests as $request) {
                if (! in_array($request->id, $lazyRequestIds, true)) {
                    continue;
                }

                if (! in_array($request->id, array_column($violations, 'request_id'), true)) {
                    $violations[] = [
                        'type' => 'n_plus_one',
                        'route' => $this->label($request),
                        'request_id' => $request->id,
                        'sql' => 'Eloquent lazy-loading violation (see request query log)',
                        'repeat_count' => null,
                        'caller_file' => null,
                        'caller_line' => null,
                    ];
                }
            }
        }

        // Attach actionable eager-load suggestions for lazy-load violations.
        $suggestions = $this->buildSuggestions($ids);

        foreach ($violations as &$violation) {
            $violation['suggestions'] = $suggestions[$violation['request_id']] ?? [];
        }

        return $violations;
    }

    protected function buildSuggestions($requestIds): array
    {
        if ($requestIds->isEmpty()) {
            return [];
        }

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

        $suggestions = [];

        foreach ($violations as $requestId => $rows) {
            $chains = $this->suggestions->build($rows->values()->all());

            $suggestions[$requestId] = array_map(
                fn ($chain) => [
                    'model' => $chain['model'],
                    'relations' => $chain['relations'],
                    'caller_file' => $chain['caller_file'],
                    'caller_line' => $chain['caller_line'],
                    'suggested' => sprintf('%s::with(%s)', $chain['model'], var_export($chain['relations'], true)),
                ],
                $chains
            );
        }

        return $suggestions;
    }

    protected function label(object $request): string
    {
        return $request->route_name ?? sprintf('%s %s', $request->method, $request->path);
    }

    protected function printResult(array $violations, array $meta, int $limit): void
    {
        if ($this->option('json') || $this->option('json-to')) {
            $this->emitJson([
                'passed' => $violations === [],
                'meta' => $meta,
                'violations' => array_slice($violations, 0, $limit),
            ]);

            return;
        }

        $rows = array_map(
            function (array $v) {
                $detail = match ($v['type']) {
                    'n_plus_one' => $v['repeat_count'] !== null
                        ? sprintf('Repeated x%s — %s', $v['repeat_count'], str_replace("\n", ' ', mb_strimwidth($v['sql'], 0, 70, '…')))
                        : 'Eloquent lazy-loading violation (see request query log)',
                    'duplicate' => sprintf('Duplicated x%s (identical bindings) — %s', $v['repeat_count'], str_replace("\n", ' ', mb_strimwidth($v['sql'], 0, 70, '…'))),
                    'unknown' => sprintf('Repeated x%s (no binding data) — %s', $v['repeat_count'], str_replace("\n", ' ', mb_strimwidth($v['sql'], 0, 70, '…'))),
                    'query_budget' => sprintf('%d queries (budget %d)', $v['query_count'], $v['budget']),
                    'duration_budget' => sprintf('%dms (budget %dms)', $v['duration_ms'], $v['budget_ms']),
                    default => '',
                };

                if (in_array($v['type'], ['n_plus_one', 'duplicate', 'unknown'], true) && $v['caller_file']) {
                    $detail .= sprintf(' at %s:%d', $v['caller_file'], $v['caller_line']);
                }

                if ($v['type'] === 'n_plus_one' && ($v['suggestions'][0]['suggested'] ?? null) !== null) {
                    $detail .= ' | '.$v['suggestions'][0]['suggested'];
                }

                if ($v['type'] === 'duplicate') {
                    $detail .= ' | Cache::remember(...) candidate';
                }

                return [
                    'type' => $v['type'],
                    'route' => $v['route'],
                    'detail' => $detail,
                ];
            },
            array_slice($violations, 0, $limit)
        );

        $this->cli->checkReport($rows, $meta['requests'], $meta['window_minutes'], $violations === []);
    }
}
