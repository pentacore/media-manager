<?php

declare(strict_types=1);

use App\Services\AiUsage\Pricing\PriceNumber;

test('normalize accepts integers, floats and decimal strings', function (mixed $input, string $expected): void {
    expect(PriceNumber::normalize($input))->toBe($expected);
})->with([
    'integer' => [15, '15'],
    'zero integer' => [0, '0'],
    'float' => [2.5, '2.5'],
    'float zero' => [0.0, '0'],
    'string keeps trailing zeros' => ['1.50', '1.50'],
    'string strips plus sign' => ['+3', '3'],
    'string trims whitespace' => [' 0.3 ', '0.3'],
    'scientific float' => [1.25e-05, '0.0000125'],
    'scientific string' => ['1.25e-05', '0.0000125'],
    'tiny per-token float' => [3.0e-07, '0.0000003'],
]);

test('normalize rejects non-numeric, negative and non-finite input', function (mixed $input): void {
    expect(PriceNumber::normalize($input))->toBeNull();
})->with([
    'negative integer' => [-1],
    'negative float' => [-0.5],
    'negative string' => ['-1'],
    'text' => ['abc'],
    'empty string' => [''],
    'infinite' => [INF],
    'nan' => [NAN],
    'null' => [null],
    'array' => [[1]],
    'boolean' => [true],
]);

test('shiftDecimal moves the decimal point exactly', function (string $decimal, int $places, string $expected): void {
    expect(PriceNumber::shiftDecimal($decimal, $places))->toBe($expected);
})->with([
    'per token to per million' => ['0.000004', 6, '4'],
    'per token fraction' => ['0.0000125', 6, '12.5'],
    'tiny per token' => ['0.00000001', 6, '0.01'],
    'whole number right' => ['2', 6, '2000000'],
    'cents per 100M to usd per million' => ['1250', -4, '0.125'],
    'exact dollars' => ['20000', -4, '2'],
    'small cents' => ['5', -4, '0.0005'],
    'zero' => ['0', 6, '0'],
    'zero left' => ['0', -4, '0'],
]);

test('withinColumnRange caps the whole part at four digits', function (string $decimal, bool $expected): void {
    expect(PriceNumber::withinColumnRange($decimal))->toBe($expected);
})->with([
    'max' => ['9999.9999', true],
    'leading zeros ignored' => ['00012.5', true],
    'too large' => ['10000', false],
    'zero' => ['0', true],
]);

test('roundToColumnScale rounds half-up to four decimals', function (string $decimal, ?string $expected): void {
    expect(PriceNumber::roundToColumnScale($decimal))->toBe($expected);
})->with([
    'pads' => ['2.5', '2.5000'],
    'rounds up at five' => ['0.00005', '0.0001'],
    'rounds down below five' => ['0.00004', '0.0000'],
    'string equal values' => ['2.50000', '2.5000'],
    'max' => ['9999.9999', '9999.9999'],
    'overflow after rounding' => ['9999.99995', null],
    'too many whole digits' => ['10000', null],
    'not a decimal' => ['abc', null],
]);
