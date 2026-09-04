<?php

use AsimAli\Pinpoint\Internal\Statistics;

// Percentile calculation: verifies nearest-rank math against known reference distributions.

test('empty input yields zero for every percentile', function () {
    expect(Statistics::percentile([], 50))->toBe(0)
        ->and(Statistics::percentile([], 95))->toBe(0)
        ->and(Statistics::percentile([], 99))->toBe(0);
});

test('single sample returns itself for every percentile', function () {
    expect(Statistics::percentile([42], 50))->toBe(42)
        ->and(Statistics::percentile([42], 95))->toBe(42)
        ->and(Statistics::percentile([42], 99))->toBe(42);
});

test('known sequence 1..10 produces the independently computed percentiles', function () {
    $values = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

    // Nearest-rank: index = ceil(p/100 * n) - 1.
    expect(Statistics::percentile($values, 50))->toBe(5)   // ceil(5.0)-1 = 4  → 5
        ->and(Statistics::percentile($values, 95))->toBe(10) // ceil(9.5)-1 = 9  → 10
        ->and(Statistics::percentile($values, 99))->toBe(10); // ceil(9.9)-1 = 9  → 10
});

test('two samples: median is the lower, p95 is the higher', function () {
    expect(Statistics::percentile([1, 2], 50))->toBe(1)
        ->and(Statistics::percentile([1, 2], 95))->toBe(2);
});

test('three samples: median is the middle value', function () {
    expect(Statistics::percentile([1, 2, 3], 50))->toBe(2)
        ->and(Statistics::percentile([1, 2, 3], 95))->toBe(3);
});

test('unsorted input yields the same percentiles as sorted input', function () {
    $sorted = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
    $shuffled = [7, 3, 10, 1, 6, 2, 9, 4, 8, 5];

    expect(Statistics::percentile($shuffled, 50))->toBe(Statistics::percentile($sorted, 50))
        ->and(Statistics::percentile($shuffled, 95))->toBe(Statistics::percentile($sorted, 95));
});

test('extreme outlier does not skew p50 or p95, only the average', function () {
    // 99 fast requests (50ms) + 1 outlier (2000ms).
    $values = array_merge(array_fill(0, 99, 50), [2000]);

    expect(Statistics::percentile($values, 50))->toBe(50)
        ->and(Statistics::percentile($values, 95))->toBe(50)
        ->and(Statistics::percentile($values, 99))->toBe(50);
});

test('101 samples: p95 lands on the 96th value of the sorted sequence', function () {
    $values = range(1, 101);

    // ceil(0.95 * 101) - 1 = ceil(95.95) - 1 = 96 - 1 = 95 → value 96.
    expect(Statistics::percentile($values, 95))->toBe(96);
});

test('p99 of 100 samples is the 99th value, p50 the 50th', function () {
    $values = range(1, 100);

    expect(Statistics::percentile($values, 50))->toBe(50)   // ceil(50)-1 = 49 → 50
        ->and(Statistics::percentile($values, 99))->toBe(99); // ceil(99)-1 = 98 → 99
});
