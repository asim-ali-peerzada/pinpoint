<?php

namespace AsimAli\Pinpoint\Internal;

/**
 * @internal Not part of Pinpoint's public API contract.
 */
class Statistics
{
    /**
     * Nearest-rank percentile. Sorts in place; pass a copy if order matters.
     */
    public static function percentile(array $durations, int $p): int
    {
        $count = count($durations);

        if ($count === 0) {
            return 0;
        }

        sort($durations);

        $rank = (int) ceil($p / 100 * $count) - 1;

        return $durations[max(0, min($rank, $count - 1))];
    }
}
