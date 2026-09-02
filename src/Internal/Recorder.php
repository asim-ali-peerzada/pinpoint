<?php

namespace AsimAli\Pinpoint\Internal;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;

/**
 * @internal The recorder is not part of Pinpoint's public API contract.
 */
class Recorder
{
    /** @var array<int, array{sql: string, fingerprint: string, bindings_hash: string|null, time_ms: float, caller: array{file: string, line: int}|null}> */
    protected array $queries = [];

    /** @var array<int, array{model: string, relation: string, caller: array{file: string, line: int}|null}> */
    protected array $lazyLoads = [];

    protected bool $flushing = false;

    public function __construct(protected Config $config) {}

    public function isRecording(): bool
    {
        return (bool) $this->config->get('pinpoint.enabled', false);
    }

    public function capturesCaller(): bool
    {
        // debug_backtrace is expensive: local/dev + CI (testing env) by default.
        // Staging can opt in explicitly with PINPOINT_CAPTURE_CALLER=true —
        // keep the frame limit tight so load tests can't OOM (see Caller.php).
        $default = app()->environment('local', 'development', 'dev', 'testing');

        $configured = $this->config->get('pinpoint.capture_caller');

        return $configured === null ? $default : (bool) $configured;
    }

    public function shouldRecord(): bool
    {
        if (! $this->isRecording()) {
            return false;
        }

        $sampleRate = (float) $this->config->get('pinpoint.sample_rate', 1.0);

        return $sampleRate >= 1.0 || ($sampleRate > 0.0 && (random_int(1, 10000) / 10000) <= $sampleRate);
    }

    public function recordQuery(array $query): void
    {
        // Never record Pinpoint's own writes: the flush inserts below fire
        // QueryExecuted and would otherwise be captured back into the array
        // being flushed (every request would self-record an "insert into
        // pinpoint_requests" query and pollute repeat/duplicate detection).
        if ($this->flushing) {
            return;
        }

        $this->queries[] = $query;
    }

    public function recordLazyLoad(string $model, string $relation, ?array $caller = null): void
    {
        $this->lazyLoads[] = ['model' => $model, 'relation' => $relation, 'caller' => $caller];
    }

    public function flush(array $request): void
    {
        $this->flushing = true;

        try {
            $id = DB::table('pinpoint_requests')->insertGetId([
                'route_name' => $request['route_name'],
                'method' => $request['method'],
                'path' => $request['path'],
                'duration_ms' => (int) round($request['duration_ms']),
                'query_count' => count($this->queries),
                'query_time_ms' => (int) round(array_sum(array_column($this->queries, 'time_ms'))),
                'has_n_plus_one' => $this->hasNPlusOne(),
                // peak_memory_kb may be absent from pre-migration callers (queue
                // jobs, custom flush calls) — never assume the key exists.
                'peak_memory_kb' => $request['peak_memory_kb'] ?? null,
                'created_at' => now(),
            ]);

            if ($this->queries !== []) {
                DB::table('pinpoint_queries')->insert(array_map(
                    fn (array $query) => [
                        'request_id' => $id,
                        'sql_fingerprint' => $query['fingerprint'],
                        'sql' => $query['sql'],
                        // bindings_hash is null when bindings are empty (no-bind
                        // queries like "select 1") or when the driver doesn't
                        // expose them. Detection treats null as "unknown".
                        'bindings_hash' => $query['bindings_hash'] ?? null,
                        'time_ms' => (int) round($query['time_ms']),
                        'caller_file' => $query['caller']['file'] ?? null,
                        'caller_line' => $query['caller']['line'] ?? null,
                        'created_at' => now(),
                    ],
                    $this->queries
                ));
            }

            if ($this->lazyLoads !== []) {
                DB::table('pinpoint_lazy_loads')->insert(array_map(
                    fn (array $lazyLoad) => [
                        'request_id' => $id,
                        'model' => $lazyLoad['model'],
                        'relation' => $lazyLoad['relation'],
                        'caller_file' => $lazyLoad['caller']['file'] ?? null,
                        'caller_line' => $lazyLoad['caller']['line'] ?? null,
                        'created_at' => now(),
                    ],
                    $this->lazyLoads
                ));
            }
        } finally {
            $this->flushing = false;
            $this->reset();
        }
    }

    public function reset(): void
    {
        $this->queries = [];
        $this->lazyLoads = [];
    }

    public function hasNPlusOne(): bool
    {
        if ($this->lazyLoads !== []) {
            return true;
        }

        $repeatThreshold = (int) $this->config->get('pinpoint.n_plus_one_repeat_threshold', 3);

        $counts = array_count_values(array_column($this->queries, 'fingerprint'));

        return $counts !== [] && max($counts) >= $repeatThreshold;
    }

    /**
     * Classify each repeated fingerprint group as either a true N+1 or an
     * exact duplicate (same SQL *and* same bound values).
     *
     * Returns an array keyed by fingerprint, each value having:
     *   - 'count'     int    total occurrences
     *   - 'type'      string 'n_plus_one' | 'duplicate' | 'unknown'
     *   - 'sql'       string a representative SQL string for that fingerprint
     *
     * Classification rules:
     *   'duplicate'  — every occurrence shares the same non-null bindings_hash
     *                  → the same query ran with the same values → cache candidate
     *   'n_plus_one' — occurrences share the same fingerprint but bindings differ
     *                  → varying IDs per iteration → eager-load candidate
     *   'unknown'    — at least one occurrence has a null bindings_hash (no
     *                  bindings data was recorded, e.g. raw DB::statement())
     *                  → cannot determine type, report conservatively
     *
     * @return array<string, array{count: int, type: string, sql: string}>
     */
    public function classifyRepeatGroups(): array
    {
        $threshold = (int) $this->config->get('pinpoint.n_plus_one_repeat_threshold', 3);

        // Group queries by fingerprint, collecting bindings_hash values.
        $groups = [];

        foreach ($this->queries as $query) {
            $fp = $query['fingerprint'];

            if (! isset($groups[$fp])) {
                $groups[$fp] = [
                    'count' => 0,
                    'sql' => $query['sql'],
                    'bindings_hashes' => [],
                ];
            }

            $groups[$fp]['count']++;
            $groups[$fp]['bindings_hashes'][] = $query['bindings_hash'] ?? null;
        }

        $result = [];

        foreach ($groups as $fp => $group) {
            if ($group['count'] < $threshold) {
                continue;
            }

            $hashes = $group['bindings_hashes'];
            $hasNull = in_array(null, $hashes, true);

            if ($hasNull) {
                $type = 'unknown';
            } elseif (count(array_unique($hashes)) === 1) {
                // All occurrences have the same non-null bindings hash.
                $type = 'duplicate';
            } else {
                // Multiple distinct binding sets → true N+1 pattern.
                $type = 'n_plus_one';
            }

            $result[$fp] = [
                'count' => $group['count'],
                'type' => $type,
                'sql' => $group['sql'],
            ];
        }

        return $result;
    }

    public function repeatCounts(): array
    {
        $counts = array_count_values(array_column($this->queries, 'fingerprint'));

        $threshold = (int) $this->config->get('pinpoint.n_plus_one_repeat_threshold', 3);

        return array_filter($counts, fn (int $count) => $count >= $threshold);
    }

    public function lazyLoads(): array
    {
        return $this->lazyLoads;
    }

    public function queries(): array
    {
        return $this->queries;
    }
}
