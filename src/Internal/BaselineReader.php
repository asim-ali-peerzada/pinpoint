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
        if (str_ends_with($tag, '.json') || is_file($tag)) {
            return $this->loadFromFile($tag);
        }

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

        if (! is_array($rows)) {
            throw new BaselineException(
                sprintf('Baseline "%s" does not contain a valid routes list.', $tag)
            );
        }

        return $this->hydrateRows($rows);
    }

    /**
     * @return array<int, array{route: string, p50: int, p95: int, p99: int, avg: int,
     *                          samples: int, tier: string, n1_repeat: int,
     *                          peak_memory_kb: int|null, query_count: int, has_duplicate_queries: bool, duplicate_repeat: int, unknown_repeat: int}>
     *
     * @throws BaselineException
     */
    public function loadFromFile(string $path): array
    {
        if (! file_exists($path)) {
            throw new BaselineException(sprintf('Baseline file "%s" not found.', $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new BaselineException(sprintf('Failed to read baseline file "%s".', $path));
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BaselineException(
                sprintf('Baseline file "%s" is corrupt (invalid JSON): %s.', $path, $e->getMessage())
            );
        }

        $rows = isset($data['routes']) && is_array($data['routes']) ? $data['routes'] : $data;

        if (! is_array($rows)) {
            throw new BaselineException(
                sprintf('Baseline file "%s" does not contain a valid routes list.', $path)
            );
        }

        return $this->hydrateRows($rows);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array{route: string, p50: int, p95: int, p99: int, avg: int,
     *                          samples: int, tier: string, n1_repeat: int,
     *                          peak_memory_kb: int|null, query_count: int, has_duplicate_queries: bool, duplicate_repeat: int, unknown_repeat: int}>
     */
    protected function hydrateRows(array $rows): array
    {
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
