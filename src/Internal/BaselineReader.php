<?php

namespace AsimAli\Pinpoint\Internal;

use AsimAli\Pinpoint\Exceptions\BaselineException;
use Illuminate\Support\Facades\DB;

/**
 * @internal Reads, validates, and resolves named baseline snapshots for
 * pinpoint:diff.
 */
class BaselineReader
{
    /**
     * @return array<int, array{route: string, p50: int, p95: int, p99: int, avg: int,
     *                          samples: int, tier: string, n1_repeat: int,
     *                          peak_memory_kb: int|null, query_count: int, has_duplicate_queries: bool, duplicate_repeat: int, unknown_repeat: int}>
     *
     * @throws BaselineException
     */
    public function load(string $tag): array
    {
        $row = DB::table('pinpoint_baselines')
            ->where('tag', $tag)
            ->orderByDesc('created_at')
            ->first();

        if ($row === null) {
            throw new BaselineException(sprintf(
                'Baseline tag "%s" not found. Available tags: %s.',
                $tag,
                $this->availableTagList()
            ));
        }

        try {
            $rows = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BaselineException(
                sprintf('Baseline "%s" is corrupt (invalid JSON): %s.', $tag, $e->getMessage())
            );
        }

        // Defensive hydration — new snapshot fields don't break old baselines.
        return array_map(fn (array $r) => array_merge([
            'route' => '',
            'p50' => 0,
            'p95' => 0,
            'p99' => 0,
            'avg' => 0,
            'samples' => 0,
            'tier' => 'good',
            'n1_repeat' => 0,
            'peak_memory_kb' => null,
            'query_count' => 0,
            'has_duplicate_queries' => false,
            'duplicate_repeat' => 0,
            'unknown_repeat' => 0,
        ], $r), $rows);
    }

    /**
     * @return array<string, \stdClass> tag => row
     */
    public function list(): array
    {
        return DB::table('pinpoint_baselines')
            ->orderByDesc('created_at')
            ->get(['tag', 'created_at', 'route_count'])
            ->mapWithKeys(fn ($r) => [$r->tag => $r])
            ->all();
    }

    protected function availableTagList(): string
    {
        $tags = array_keys(array_slice($this->list(), 0, 10));

        return $tags === [] ? '(none)' : implode(', ', $tags);
    }
}
