<?php

namespace AsimAli\Pinpoint\Internal;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @internal Not part of Pinpoint's public API contract.
 */
class QueryReader
{
    /**
     * Scope a pinpoint_requests query to a route label: either a route name,
     * or a "METHOD path" fallback label matching unnamed routes.
     *
     * Both branches live inside ONE grouped where — an ungrouped top-level
     * orWhere silently changes meaning the moment another leading filter is
     * composed onto the query.
     */
    public static function scopeRouteLabel(Builder $query, string $routeLabel): Builder
    {
        return $query->where(function ($q) use ($routeLabel) {
            $q->where('route_name', $routeLabel);

            if (str_contains($routeLabel, ' ')) {
                [$method, $path] = explode(' ', $routeLabel, 2);

                $q->orWhere(fn ($inner) => $inner
                    ->whereNull('route_name')
                    ->where('method', $method)
                    ->where('path', $path));
            }
        });
    }

    /**
     * Top offending queries for a route label (route name, or "METHOD path"),
     * ordered by slowest max time.
     *
     * Each row includes a `query_type` column that classifies repeated queries:
     *   'duplicate'  — same fingerprint AND same bindings_hash across all rows
     *                  → the identical query ran multiple times → Cache::remember()
     *   'n_plus_one' — same fingerprint, different bindings_hash values
     *                  → the same shape with varying IDs → Model::with()
     *   'unknown'    — at least one row has null bindings_hash (no binding data
     *                  recorded, e.g. raw DB::statement()) → conservative report
     *   null         — query ran fewer times than the repeat threshold (not flagged)
     *
     * Classification is done in PHP after aggregation to stay compatible with
     * SQLite (used in tests) and MySQL/PostgreSQL, avoiding DB-level JSON ops.
     *
     * @return Collection<int, \stdClass>
     */
    public function topQueries(string $routeLabel, int $limit = 20): Collection
    {
        $requestIds = self::scopeRouteLabel(
            DB::table('pinpoint_requests')->select('id'),
            $routeLabel
        )->pluck('id');

        if ($requestIds->isEmpty()) {
            return collect();
        }

        // Fetch all per-fingerprint per-request rows so we can classify
        // duplicate vs N+1 in PHP. We do NOT select individual bindings_hash
        // values here (that would multiply rows) — instead we count them.
        $rows = DB::table('pinpoint_queries as q')
            ->whereIn('q.request_id', $requestIds)
            ->select('q.sql_fingerprint', 'q.sql', 'q.caller_file', 'q.caller_line')
            ->selectRaw('COUNT(*) as repeat_count')
            ->selectRaw('MAX(q.time_ms) as max_ms')
            ->selectRaw('AVG(q.time_ms) as avg_ms')
            // Count distinct bindings_hash values (nulls ignored by COUNT).
            ->selectRaw('COUNT(DISTINCT q.bindings_hash) as distinct_binding_count')
            // Count rows that have a NULL bindings_hash.
            ->selectRaw('SUM(CASE WHEN q.bindings_hash IS NULL THEN 1 ELSE 0 END) as null_binding_count')
            ->groupBy('q.sql_fingerprint', 'q.sql', 'q.caller_file', 'q.caller_line')
            ->orderByDesc('max_ms')
            ->limit($limit)
            ->get();

        $threshold = (int) config('pinpoint.n_plus_one_repeat_threshold', 3);

        return $rows->map(function (\stdClass $row) use ($threshold): \stdClass {
            $row->query_type = null;

            if ($row->repeat_count >= $threshold) {
                $row->query_type = $this->classifyFromAggregates(
                    (int) $row->distinct_binding_count,
                    (int) $row->null_binding_count
                );
            }

            return $row;
        });
    }

    /**
     * Classify a repeated fingerprint group from DB-level aggregate counts.
     *
     *   null_binding_count > 0   → some rows have no binding data → 'unknown'
     *   distinct_binding_count == 1 → all rows share one hash → 'duplicate'
     *   distinct_binding_count > 1  → multiple hash values → 'n_plus_one'
     *   distinct_binding_count == 0 → all nulls (handled by null_binding check)
     */
    protected function classifyFromAggregates(int $distinctCount, int $nullCount): string
    {
        if ($nullCount > 0) {
            return 'unknown';
        }

        // All bindings_hash values are non-null (nullCount == 0).
        return $distinctCount === 1 ? 'duplicate' : 'n_plus_one';
    }
}
