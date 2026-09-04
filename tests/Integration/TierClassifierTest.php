<?php

use AsimAli\Pinpoint\TierClassifier;

test('partial route threshold overrides fall back to defaults', function () {
    config()->set('pinpoint.route_threshold_overrides', [
        'api.partial' => ['good' => 50],
    ]);

    $classifier = app(TierClassifier::class);

    expect($classifier->classify(100, 'api.partial'))->toBe('acceptable');
    expect($classifier->classify(600, 'api.partial'))->toBe('needs_improvement');
    expect($classifier->classify(2000, 'api.partial'))->toBe('critical');
});

test('full overrides still apply wholesale', function () {
    config()->set('pinpoint.route_threshold_overrides', [
        'api.export' => ['good' => 1000, 'acceptable' => 3000, 'needs_improvement' => 8000],
    ]);

    $classifier = app(TierClassifier::class);

    expect($classifier->classify(900, 'api.export'))->toBe('good');
    expect($classifier->classify(5000, 'api.export'))->toBe('needs_improvement');
    expect($classifier->classify(10000, 'api.export'))->toBe('critical');
});

// Tier boundary verification: ensures deterministic classification at threshold − 1,
// threshold, and threshold + 1. Default boundaries: good <= 150ms, acceptable <= 400ms,
// needs_improvement <= 1000ms. Comparisons are inclusive (<=).

test('good/acceptable boundary: 149, 150, 151', function () {
    $classifier = app(TierClassifier::class);

    expect($classifier->classify(149, null))->toBe('good')
        ->and($classifier->classify(150, null))->toBe('good')
        ->and($classifier->classify(151, null))->toBe('acceptable');
});

test('acceptable/needs_improvement boundary: 399, 400, 401', function () {
    $classifier = app(TierClassifier::class);

    expect($classifier->classify(399, null))->toBe('acceptable')
        ->and($classifier->classify(400, null))->toBe('acceptable')
        ->and($classifier->classify(401, null))->toBe('needs_improvement');
});

test('needs_improvement/critical boundary: 999, 1000, 1001', function () {
    $classifier = app(TierClassifier::class);

    expect($classifier->classify(999, null))->toBe('needs_improvement')
        ->and($classifier->classify(1000, null))->toBe('needs_improvement')
        ->and($classifier->classify(1001, null))->toBe('critical');
});

test('classification is deterministic for repeated identical input', function () {
    $classifier = app(TierClassifier::class);

    expect($classifier->classify(399.7, null))->toBe($classifier->classify(399.7, null))
        ->and($classifier->classify(1000.0, null))->toBe($classifier->classify(1000.0, null));
});

test('custom global thresholds shift every boundary', function () {
    config()->set('pinpoint.thresholds_ms', ['good' => 50, 'acceptable' => 120, 'needs_improvement' => 300]);

    $classifier = app(TierClassifier::class);

    expect($classifier->classify(49, null))->toBe('good')
        ->and($classifier->classify(50, null))->toBe('good')
        ->and($classifier->classify(51, null))->toBe('acceptable')
        ->and($classifier->classify(301, null))->toBe('critical');
});

test('route overrides respect the same inclusive boundary semantics', function () {
    config()->set('pinpoint.route_threshold_overrides', [
        'api.partial' => ['good' => 50],
    ]);

    $classifier = app(TierClassifier::class);

    // 'acceptable' falls back to the default 400.
    expect($classifier->classify(50, 'api.partial'))->toBe('good')
        ->and($classifier->classify(51, 'api.partial'))->toBe('acceptable')
        ->and($classifier->classify(400, 'api.partial'))->toBe('acceptable')
        ->and($classifier->classify(401, 'api.partial'))->toBe('needs_improvement');
});
