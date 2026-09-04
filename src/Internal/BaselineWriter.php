<?php

namespace AsimAli\Pinpoint\Internal;

use AsimAli\Pinpoint\Exceptions\BaselineException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * @internal Writes a named baseline snapshot of current per-route metrics
 * to pinpoint_baselines for later comparison by pinpoint:diff.
 */
class BaselineWriter
{
    public const MAX_TAG_LENGTH = 100;

    public function __construct(protected SummaryReader $summaries) {}

    /**
     * Validate tag → compute current metrics → persist atomically.
     *
     * @throws BaselineException|\InvalidArgumentException
     */
    public function write(string $tag, bool $overwrite = true, ?int $sinceMinutes = null, ?string $filePath = null): int
    {
        $tag = $this->validateTag($tag);
        $rows = $this->summaries->fromRaw($sinceMinutes);

        if ($rows === []) {
            throw new BaselineException(
                'No requests recorded — snapshot not created. Run requests first.'
            );
        }

        if ($filePath !== null) {
            $dir = dirname($filePath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($filePath, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        if (! $overwrite && DB::table('pinpoint_baselines')->where('tag', $tag)->exists()) {
            throw new BaselineException(sprintf(
                'Snapshot "%s" already exists. Use a different tag (or allow overwrite).',
                $tag
            ));
        }

        try {
            DB::transaction(function () use ($tag, $rows, $overwrite) {
                if ($overwrite) {
                    DB::table('pinpoint_baselines')->where('tag', $tag)->delete();
                }

                DB::table('pinpoint_baselines')->insert([
                    'tag' => $tag,
                    'snapshot' => json_encode($rows, JSON_THROW_ON_ERROR),
                    'route_count' => count($rows),
                    'created_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'no such table')) {
                throw new BaselineException(
                    'pinpoint_baselines is missing — run `php artisan vendor:publish --tag=pinpoint-migrations` then `php artisan migrate`.'
                );
            }

            // Backstop for the concurrent --no-overwrite race: the unique tag
            // constraint rejects the second writer inside the transaction.
            throw new BaselineException(sprintf(
                'Could not save snapshot "%s" (concurrent write?): %s',
                $tag,
                $e->getMessage()
            ), 0, $e);
        }

        return count($rows);
    }

    protected function validateTag(string $tag): string
    {
        $tag = trim($tag);

        if ($tag === '') {
            throw new \InvalidArgumentException('Snapshot tag cannot be empty.');
        }

        if (strlen($tag) > self::MAX_TAG_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('Snapshot tag exceeds %d characters.', self::MAX_TAG_LENGTH)
            );
        }

        if (! preg_match('/^[\w.\-\/]+$/', $tag)) {
            throw new \InvalidArgumentException(
                'Snapshot tag may only contain letters, digits, dots, dashes, underscores, and slashes.'
            );
        }

        return $tag;
    }
}
