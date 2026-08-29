<?php

namespace AsimAli\Pinpoint;

class QueryFingerprinter
{
    public static function hash(string $sql): string
    {
        // Collapse IN (?, ?, ?, ...) lists of any length down to a single placeholder
        // so two calls to the same eager-load with different batch sizes still match.
        $normalized = preg_replace('/\?(,\s*\?)+/', '?', $sql);

        // md5 (not crc32) so distinct query patterns can't collide into one
        // fingerprint — a collision would falsely flag unrelated queries as N+1.
        return md5($normalized);
    }
}
