<?php

namespace AsimAli\Pinpoint;

use Illuminate\Contracts\Config\Repository as Config;

class TierClassifier
{
    public const GOOD = 'good';
    public const ACCEPTABLE = 'acceptable';
    public const NEEDS_IMPROVEMENT = 'needs_improvement';
    public const CRITICAL = 'critical';

    public function __construct(protected Config $config)
    {
    }

    public function classify(float $ms, ?string $routeName): string
    {
        $thresholds = $this->thresholdsFor($routeName);

        if ($ms <= $thresholds['good']) {
            return self::GOOD;
        }

        if ($ms <= $thresholds['acceptable']) {
            return self::ACCEPTABLE;
        }

        if ($ms <= $thresholds['needs_improvement']) {
            return self::NEEDS_IMPROVEMENT;
        }

        return self::CRITICAL;
    }

    protected function thresholdsFor(?string $routeName): array
    {
        $defaults = $this->config->get('pinpoint.thresholds_ms');

        $overrides = $this->config->get('pinpoint.route_threshold_overrides', []);

        return $routeName !== null && isset($overrides[$routeName])
            ? $overrides[$routeName]
            : $defaults;
    }
}