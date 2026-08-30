<?php

namespace AsimAli\Pinpoint\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PruneCommand extends Command
{
    protected $signature = 'pinpoint:prune {--days= : Override the retention window in days}';

    protected $description = 'Delete raw pinpoint data older than the retention window';

    public function handle(): int
    {
        try {
            $days = (int) ($this->option('days') ?? config('pinpoint.retention_days', 30));

            if ($days < 1) {
                $this->error('Retention window must be a positive number of days.');

                return self::FAILURE;
            }

            $cutoff = now()->subDays($days);

            $requests = DB::table('pinpoint_requests')->where('created_at', '<', $cutoff)->delete();

            $this->info(sprintf('Pruned %d request(s) and their associated queries/lazy loads older than %d day(s).', $requests, $days));
        } catch (Throwable $e) {
            Log::error('Pinpoint: prune failed', ['exception' => $e->getMessage()]);
            $this->error('Pinpoint prune failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
