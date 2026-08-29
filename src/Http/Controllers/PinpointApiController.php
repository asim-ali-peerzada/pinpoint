<?php

namespace AsimAli\Pinpoint\Http\Controllers;

use AsimAli\Pinpoint\Internal\QueryReader;
use AsimAli\Pinpoint\Internal\SummaryReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class PinpointApiController extends Controller
{
    public function __construct(
        protected SummaryReader $summaries,
        protected QueryReader $queries,
    ) {}

    public function summaries(): JsonResponse
    {
        return response()->json(['data' => $this->summaries->fromRaw()]);
    }

    public function topQueries(string $route): JsonResponse
    {
        $queries = $this->queries->topQueries($route, 20);

        return response()->json(['data' => $queries]);
    }
}
