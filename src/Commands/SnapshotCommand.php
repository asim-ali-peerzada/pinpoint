<?php

namespace AsimAli\Pinpoint\Commands;

use AsimAli\Pinpoint\Exceptions\BaselineException;
use AsimAli\Pinpoint\Internal\BaselineWriter;
use AsimAli\Pinpoint\Internal\CliRenderer;
use AsimAli\Pinpoint\Internal\SinceParser;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SnapshotCommand extends Command
{
    protected $signature = 'pinpoint:snapshot
        {--tag=main : Name for this snapshot (e.g. main, v2.1.0, before-refactor)}
        {--since= : Only snapshot requests from the last N (e.g. 30m, 2h)}
        {--no-overwrite : Fail if a snapshot with this tag already exists}';

    protected $description = 'Capture current performance metrics as a named baseline for future diffs';

    public function __construct(
        protected BaselineWriter $baselines,
        protected CliRenderer $cli,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $count = $this->baselines->write(
                (string) $this->option('tag'),
                overwrite: ! $this->option('no-overwrite'),
                sinceMinutes: $this->resolveSinceMinutes()
            );
        } catch (InvalidArgumentException|BaselineException $e) {
            $this->cli->info($e->getMessage());

            return self::FAILURE;
        }

        $this->cli->info(sprintf('Snapshot "%s" saved — %d route(s).', $this->option('tag'), $count));

        return self::SUCCESS;
    }

    protected function resolveSinceMinutes(): ?int
    {
        $since = $this->option('since');

        return $since !== null ? SinceParser::toMinutes($since) : null;
    }
}
