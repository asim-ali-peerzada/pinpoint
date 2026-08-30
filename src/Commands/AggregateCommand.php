<?php

namespace AsimAli\Pinpoint\Commands;

use AsimAli\Pinpoint\Internal\SummaryReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AggregateCommand extends Command
{
    protected $signature = 'pinpoint:aggregate';

    protected $description = 'Roll raw pinpoint_requests into per-route percentile summaries';

    public function __construct(protected SummaryReader $summaries)
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
        $summaries = $this->summaries->fromRaw();

        // One transaction for the whole batch: a mid-aggregate failure must
        // not leave the summaries table as a mixed snapshot of fresh and
        // stale percentiles. Readers see either all-old or all-new.
        DB::transaction(function () use ($summaries) {
            foreach ($summaries as $summary) {
                DB::table('pinpoint_summaries')->updateOrInsert(
                    ['route_name' => $summary['route']],
                    [
                        'p50_ms' => $summary['p50'],
                        'p95_ms' => $summary['p95'],
                        'p99_ms' => $summary['p99'],
                        'avg_ms' => $summary['avg'],
                        'sample_count' => $summary['samples'],
                        'tier' => $summary['tier'],
                        'last_computed_at' => now(),
                    ]
                );
            }
        });

        $this->info(sprintf('Aggregated %d route(s).', count($summaries)));
    }
}
