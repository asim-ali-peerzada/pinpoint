<?php

namespace AsimAli\Pinpoint\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PruneCommand extends Command
{
    private const MAX_RETENTION_DAYS = 36500;

    protected $signature = 'pinpoint:prune {--days= : Override the retention window in days}';

    protected $description = 'Delete raw pinpoint data older than the retention window';

    public function handle(): int
    {
        try {
            $days = (int) ($this->option('days') ?? config('pinpoint.retention_days', 30));

            if ($days < 1 || $days > self::MAX_RETENTION_DAYS) {
                $this->error(sprintf(
                    'Retention window must be between 1 and %d days.',
                    self::MAX_RETENTION_DAYS
                ));

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
