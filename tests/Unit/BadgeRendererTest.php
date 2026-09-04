<?php

use AsimAli\Pinpoint\Internal\BadgeRenderer;

test('sparkline renders full shaded blocks for zero or negative latency', function () {
    $rendered = BadgeRenderer::sparkline(0, 'good', 1000, 12);

    expect($rendered)->toContain(str_repeat('·', 12));
});

test('sparkline renders full solid blocks for latency at or exceeding budget', function () {
    $rendered = BadgeRenderer::sparkline(2158, 'critical', 1000, 12);

    expect($rendered)
        ->toContain('text-red-500')
        ->toContain(str_repeat('■', 12));
});

test('sparkline renders thin block for small non-zero latencies', function () {
    $rendered = BadgeRenderer::sparkline(9, 'good', 1000, 12);

    expect($rendered)
        ->toContain('text-green-500')
        ->toContain('▪')
        ->toContain(str_repeat('·', 11));
});

test('sparkline renders proportional split for mid-range latency', function () {
    // 500ms out of 1000ms budget with width 12 => 6 solid, 6 shaded
    $rendered = BadgeRenderer::sparkline(500, 'needs_improvement', 1000, 12);

    expect($rendered)
        ->toContain('text-yellow-500')
        ->toContain(str_repeat('■', 6))
        ->toContain(str_repeat('·', 6));
});

test('sparkline uses correct color classes for each tier', function () {
    expect(BadgeRenderer::sparkline(100, 'critical', 1000, 10))->toContain('text-red-500')
        ->and(BadgeRenderer::sparkline(100, 'needs_improvement', 1000, 10))->toContain('text-yellow-500')
        ->and(BadgeRenderer::sparkline(100, 'acceptable', 1000, 10))->toContain('text-blue-400')
        ->and(BadgeRenderer::sparkline(100, 'good', 1000, 10))->toContain('text-green-500');
});
