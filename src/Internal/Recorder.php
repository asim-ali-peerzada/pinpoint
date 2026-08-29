<?php

namespace AsimAli\Pinpoint\Internal;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @internal The recorder is not part of Pinpoint's public API contract.
 */
class Recorder
{
    /** @var array<int, array{sql: string, fingerprint: string, time_ms: float, caller: array{file: string, line: int}|null}> */
    protected array $queries = [];

    /** @var array<int, array{model: string, relation: string, caller: array{file: string, line: int}|null}> */
    protected array $lazyLoads = [];

    public function __construct(protected Config $config) {}

    public function isRecording(): bool
    {
        return (bool) $this->config->get('pinpoint.enabled', false);
    }

    public function capturesCaller(): bool
    {
        // debug_backtrace is expensive: local + CI (testing env) only, and
        // the config flag lets you turn it off entirely. CI needs callers so
        // pinpoint:check can report the exact file:line that caused an N+1.
        $inTestEnv = app()->environment() === 'testing';

        return (app()->isLocal() || $inTestEnv) && (bool) $this->config->get('pinpoint.capture_caller', true);
    }

    public function shouldRecord(Request $request): bool
    {
        if (! $this->isRecording()) {
            return false;
        }

        return mt_rand() / mt_getrandmax() < (float) $this->config->get('pinpoint.sample_rate', 1.0);
    }

    public function recordQuery(array $query): void
    {
        $this->queries[] = $query;
    }

    public function recordLazyLoad(string $model, string $relation, ?array $caller = null): void
    {
        $this->lazyLoads[] = ['model' => $model, 'relation' => $relation, 'caller' => $caller];
    }

    public function flush(array $request): void
    {
        $id = DB::table('pinpoint_requests')->insertGetId([
            'route_name' => $request['route_name'],
            'method' => $request['method'],
            'path' => $request['path'],
            'duration_ms' => (int) round($request['duration_ms']),
            'query_count' => count($this->queries),
            'query_time_ms' => (int) round(array_sum(array_column($this->queries, 'time_ms'))),
            'has_n_plus_one' => $this->hasNPlusOne(),
            'created_at' => now(),
        ]);

        if ($this->queries !== []) {
            DB::table('pinpoint_queries')->insert(array_map(
                fn (array $query) => [
                    'request_id' => $id,
                    'sql_fingerprint' => $query['fingerprint'],
                    'sql' => $query['sql'],
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

        $this->reset();
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
