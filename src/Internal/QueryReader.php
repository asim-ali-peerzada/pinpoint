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

        return DB::table('pinpoint_queries as q')
            ->whereIn('q.request_id', $requestIds)
            ->select('q.sql_fingerprint', 'q.sql', 'q.caller_file', 'q.caller_line')
            ->selectRaw('COUNT(*) as repeat_count')
            ->selectRaw('MAX(q.time_ms) as max_ms')
            ->selectRaw('AVG(q.time_ms) as avg_ms')
            ->groupBy('q.sql_fingerprint', 'q.sql', 'q.caller_file', 'q.caller_line')
            ->orderByDesc('max_ms')
            ->limit($limit)
            ->get();
    }
}
