<?php

use AsimAli\Pinpoint\Internal\DiffCalculator;

/**
 * @return array<string, mixed>
 */
function diffRow(array $overrides = []): array
{
    return array_merge([
        'route' => 'api.orders',
        'p50' => 80,
        'p95' => 100,
        'p99' => 120,
        'avg' => 90,
        'samples' => 10,
        'tier' => 'good',
        'n1_repeat' => 0,
        'peak_memory_kb' => 4096,
        'query_count' => 2,
        'has_duplicate_queries' => false,
    ], $overrides);
}

$calculator = new DiffCalculator;

test('identical metrics are stable', function () use ($calculator) {
    $diffs = $calculator->compare([diffRow()], [diffRow()], 20, 3, 50, 1);

    expect($diffs[0]['status'])->toBe(DiffCalculator::STATUS_STABLE);
});

test('p95 above the duration threshold is a regression', function () use ($calculator) {
    $diffs = $calculator->compare([diffRow()], [diffRow(['p95' => 125])], 20, 3, 50, 1);

    expect($diffs[0]['status'])->toBe(DiffCalculator::STATUS_REGRESSION)
        ->and($diffs[0]['changes']['p95_pct'])->toBe(25.0);
});

test('p95 below the duration threshold is stable', function () use ($calculator) {
    $diffs = $calculator->compare([diffRow()], [diffRow(['p95' => 110])], 20, 3, 50, 1);

    expect($diffs[0]['status'])->toBe(DiffCalculator::STATUS_STABLE);
});

test('n1 introduced always triggers a regression regardless of threshold', function () use ($calculator) {
    $diffs = $calculator->compare([diffRow()], [diffRow(['n1_repeat' => 1])], 20, 3, 50, 1);

    expect($diffs[0]['status'])->toBe(DiffCalculator::STATUS_REGRESSION)
        ->and($diffs[0]['changes']['n1_introduced'])->toBeTrue();
});

test('n1 delta above the query threshold is a regression', function () use ($calculator) {
    $diffs = $calculator->compare([diffRow()], [diffRow(['n1_repeat' => 5])], 20, 3, 50, 1);

    expect($diffs[0]['status'])->toBe(DiffCalculator::STATUS_REGRESSION)
        ->and($diffs[0]['changes']['n1_delta'])->toBe(5);
});

test('memory above the memory threshold is a regression', function () use ($calculator) {
    $diffs = $calculator->compare([diffRow()], [diffRow(['peak_memory_kb' => 7000])], 20, 3, 50, 1);

    expect($diffs[0]['status'])->toBe(DiffCalculator::STATUS_REGRESSION)
        ->and($diffs[0]['changes']['memory_pct'])->toBe(70.9);
});

test('p95 drop beyond the improvement margin is an improvement', function () use ($calculator) {
    $diffs = $calculator->compare([diffRow()], [diffRow(['p95' => 80])], 20, 3, 50, 1);

    expect($diffs[0]['status'])->toBe(DiffCalculator::STATUS_IMPROVEMENT);
});

test('baseline p95 of zero yields a null percentage and no duration regression', function () use ($calculator) {
    $diffs = $calculator->compare([diffRow(['p95' => 0])], [diffRow(['p95' => 100])], 20, 3, 50, 1);

    expect($diffs[0]['changes']['p95_pct'])->toBeNull()
        ->and($diffs[0]['status'])->toBe(DiffCalculator::STATUS_STABLE);
});

test('routes below the minimum sample floor are never flagged', function () use ($calculator) {
    // One sample per side (the snapshot → one request → diff loop): a noisy
    // single request must not flag a route as a regression.
    $diffs = $calculator->compare(
        [diffRow(['samples' => 1])],
        [diffRow(['samples' => 1, 'p95' => 200])],
        20, 3, 50, 3
    );

    expect($diffs[0]['status'])->toBe(DiffCalculator::STATUS_STABLE);
});

test('routes at or above the sample floor are judged normally', function () use ($calculator) {
    $diffs = $calculator->compare(
        [diffRow(['samples' => 3])],
        [diffRow(['samples' => 3, 'p95' => 200])],
        20, 3, 50, 3
    );

    expect($diffs[0]['status'])->toBe(DiffCalculator::STATUS_REGRESSION);
});

test('route only in the baseline is removed, never a regression', function () use ($calculator) {
    $diffs = $calculator->compare([diffRow(), diffRow(['route' => 'api.old'])], [diffRow()], 20, 3, 50, 1);

    expect($diffs)->toHaveCount(2)
        ->and(collect($diffs)->firstWhere('route', 'api.old')['status'])->toBe(DiffCalculator::STATUS_REMOVED);
});

test('route only in the current is new, never a regression', function () use ($calculator) {
    $diffs = $calculator->compare([diffRow()], [diffRow(), diffRow(['route' => 'api.brand-new'])], 20, 3, 50, 1);

    expect($diffs)->toHaveCount(2)
        ->and(collect($diffs)->firstWhere('route', 'api.brand-new')['status'])->toBe(DiffCalculator::STATUS_NEW);
});

test('regressions sort before improvements and stable routes', function () use ($calculator) {
    $baseline = [
        diffRow(),
        diffRow(['route' => 'api.slow']),
        diffRow(['route' => 'api.faster']),
    ];
    $current = [
        diffRow(),
        diffRow(['route' => 'api.slow', 'p95' => 200]),
        diffRow(['route' => 'api.faster', 'p95' => 70]),
    ];

    $diffs = $calculator->compare($baseline, $current, 20, 3, 50, 1);

    expect(array_column($diffs, 'status'))->toBe([
        DiffCalculator::STATUS_REGRESSION,
        DiffCalculator::STATUS_IMPROVEMENT,
        DiffCalculator::STATUS_STABLE,
    ]);
});
