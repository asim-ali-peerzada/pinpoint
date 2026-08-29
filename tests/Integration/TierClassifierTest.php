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
