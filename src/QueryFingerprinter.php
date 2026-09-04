<?php

namespace AsimAli\Pinpoint;

class QueryFingerprinter
{
    public static function hash(string $sql): string
    {
        // Collapse IN (?, ?, ?, ...) lists of any length down to a single placeholder
        // so two calls to the same eager-load with different batch sizes still match.
        $normalized = preg_replace('/\?(,\s*\?)+/', '?', $sql);

        // Non-cryptographic fingerprint used solely to cluster normalized SQL shapes.
        return md5($normalized); // NOSONAR
    }
}
