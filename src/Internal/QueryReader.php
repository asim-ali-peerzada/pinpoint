<?php

namespace AsimAli\Pinpoint\Internal;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @internal Not part of Pinpoint's public API contract.
 */
class QueryReader
{
    /**
     * Top offending queries for a route label (route name, or "METHOD path"),
     * ordered by slowest max time.
     *
     * @return Collection<int, \stdClass>
     */
    public function topQueries(string $routeLabel, int $limit = 20): Collection
    {
        $query = DB::table('pinpoint_requests')->select('id')
            ->where('route_name', $routeLabel);

        if (str_contains($routeLabel, ' ')) {
            [$method, $path] = explode(' ', $routeLabel, 2);

            $query->orWhere(fn ($q) => $q
                ->whereNull('route_name')
                ->where('method', $method)
                ->where('path', $path));
        }

        $requestIds = $query->pluck('id');

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
