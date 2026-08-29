<?php

use AsimAli\Pinpoint\Internal\SinceParser;

test('bare numbers are minutes', function () {
    expect(SinceParser::toMinutes('5'))->toBe(5);
    expect(SinceParser::toMinutes('60'))->toBe(60);
});

test('unit suffixes are parsed', function () {
    expect(SinceParser::toMinutes('5m'))->toBe(5);
    expect(SinceParser::toMinutes('5min'))->toBe(5);
    expect(SinceParser::toMinutes('1h'))->toBe(60);
    expect(SinceParser::toMinutes('2h'))->toBe(120);
    expect(SinceParser::toMinutes('1d'))->toBe(1440);
});

test('invalid durations throw', function (string $value) {
    SinceParser::toMinutes($value);
})->with(['abc', '1x', '0', '0m', '-5m', '5 minutes'])->throws(InvalidArgumentException::class);
