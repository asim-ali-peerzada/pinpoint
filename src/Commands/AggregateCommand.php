<?php

namespace AsimAli\Pinpoint\Commands;

use AsimAli\Pinpoint\Internal\Statistics;
use AsimAli\Pinpoint\TierClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AggregateCommand extends Command
{
    protected $signature = 'pinpoint:aggregate';

    protected $description = 'Roll raw pinpoint_requests into per-route percentile summaries';

    public function __construct(protected TierClassifier $tiers)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $this->aggregate();
        } catch (Throwable $e) {
            Log::error('Pinpoint: aggregation failed', ['exception' => $e->getMessage()]);
            $this->error('Pinpoint aggregation failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function aggregate(): void
    {
        $rows = DB::table('pinpoint_requests')
            ->select('route_name')
            ->selectRaw('COUNT(*) as sample_count')
            ->selectRaw('AVG(duration_ms) as avg_ms')
            ->groupBy('route_name')
            ->get();

        foreach ($rows as $row) {
            $durations = $this->durationsFor($row->route_name);

            DB::table('pinpoint_summaries')->updateOrInsert(
                ['route_name' => $row->route_name],
                [
                    'p50_ms' => Statistics::percentile($durations, 50),
                    'p95_ms' => Statistics::percentile($durations, 95),
                    'p99_ms' => Statistics::percentile($durations, 99),
                    'avg_ms' => (int) round($row->avg_ms),
                    'sample_count' => (int) $row->sample_count,
                    'tier' => $this->tiers->classify(Statistics::percentile($durations, 95), $row->route_name),
                    'last_computed_at' => now(),
                ]
            );
        }

        $this->info(sprintf('Aggregated %d route(s).', count($rows)));
    }

    protected function durationsFor(?string $routeName): array
    {
        return DB::table('pinpoint_requests')
            ->where('route_name', $routeName)
            ->orderBy('duration_ms')
            ->pluck('duration_ms')
            ->map(fn ($ms) => (int) $ms)
            ->all();
    }
}
