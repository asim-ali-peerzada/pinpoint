<?php

namespace AsimAli\Pinpoint\Internal;

use InvalidArgumentException;

/**
 * @internal Not part of Pinpoint's public API contract.
 *
 * Parses human-friendly durations for --since flags:
 * "5" (minutes), "5m" / "5min", "1h", "2d".
 */
class SinceParser
{
    public static function toMinutes(string $value): int
    {
        if (! preg_match('/^(\d+)\s*(min|m|h|d|s)?$/', strtolower(trim($value)), $match)) {
            throw new InvalidArgumentException("Invalid duration: {$value}. Use e.g. 5 (minutes), 5m, 5min, 1h, 2d.");
        }

        $amount = (int) $match[1];
        $unit = $match[2] ?? 'm';

        $multipliers = [
            's' => 1 / 60,
            'm' => 1,
            'min' => 1,
            'h' => 60,
            'd' => 1440,
        ];

        $minutes = (int) round($amount * $multipliers[$unit]);

        if ($minutes < 1) {
            throw new InvalidArgumentException("Duration too small: {$value}. Minimum is 1 minute.");
        }

        return $minutes;
    }
}
