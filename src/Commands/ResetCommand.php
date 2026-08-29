<?php

namespace AsimAli\Pinpoint\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResetCommand extends Command
{
    protected $signature = 'pinpoint:reset {--force : Skip the confirmation prompt}';

    protected $description = 'Delete all recorded Pinpoint data (requests, queries, lazy loads, summaries)';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('This deletes ALL recorded Pinpoint data. Continue?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        try {
            $queries = DB::table('pinpoint_queries')->delete();
            $lazyLoads = DB::table('pinpoint_lazy_loads')->delete();
            $requests = DB::table('pinpoint_requests')->delete();
            $summaries = DB::table('pinpoint_summaries')->delete();

            $this->info(sprintf('Reset complete: %d request(s), %d query(ies), %d lazy load(s), %d summarie(s) deleted.', $requests, $queries, $lazyLoads, $summaries));
        } catch (Throwable $e) {
            Log::error('Pinpoint: reset failed', ['exception' => $e->getMessage()]);
            $this->error('Pinpoint reset failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
