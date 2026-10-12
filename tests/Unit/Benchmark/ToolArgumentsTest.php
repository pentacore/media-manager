<?php

declare(strict_types=1);

use App\Ai\Benchmark\ToolArguments;

test('normalise sorts keys recursively', function (): void {
    $normalised = ToolArguments::normalise(['b' => ['y' => 1, 'x' => 2], 'a' => 1]);

    expect(array_keys($normalised))->toBe(['a', 'b'])
        ->and(array_keys($normalised['b']))->toBe(['x', 'y']);
});

test('normalise turns numeric strings into numbers', function (): void {
    expect(ToolArguments::normalise(['a' => '5', 'b' => '2.50', 'c' => 5.0]))
        ->toBe(['a' => 5, 'b' => 2.5, 'c' => 5]);
});

test('normalise converts only plain decimal strings that round-trip exactly', function (string $value, int|float|string $normalised): void {
    expect(ToolArguments::normalise(['v' => $value]))->toBe(['v' => $normalised]);
})->with([
    'an integer' => ['5', 5],
    'a decimal with a trailing zero' => ['2.50', 2.5],
    'a negative decimal' => ['-0.25', -0.25],
    'zero' => ['0', 0],
    'a fraction below one' => ['0.5', 0.5],
    'surrounding whitespace' => [' 42 ', 42],
    'fifteen significant digits' => ['123456789012345', 123456789012345],
    'leading zeros' => ['007', '007'],
    'an exponent' => ['1e3', '1e3'],
    'an upper-case exponent' => ['2E5', '2e5'],
    'a plus sign' => ['+5', '+5'],
    'a 19-digit id' => ['1234567890123456789', '1234567890123456789'],
    'sixteen significant digits' => ['1234567890.123456', '1234567890.123456'],
    'a bare decimal point' => ['.5', '.5'],
    'a trailing decimal point' => ['5.', '5.'],
    'hexadecimal' => ['0x1a', '0x1a'],
]);

test('normalise trims, collapses whitespace and lower-cases strings', function (): void {
    expect(ToolArguments::normalise(['q' => "  Dune   Part\tTwo "]))->toBe(['q' => 'dune part two']);
});

test('normalise drops null and empty-string values', function (): void {
    expect(ToolArguments::normalise(['a' => null, 'b' => '  ', 'c' => 0, 'd' => false, 'e' => ['f' => null, 'g' => 'x']]))
        ->toBe(['c' => 0, 'd' => false, 'e' => ['g' => 'x']]);
});

test('normalise keeps booleans and does not coerce boolean strings', function (): void {
    expect(ToolArguments::normalise(['a' => true, 'b' => 'true']))->toBe(['a' => true, 'b' => 'true']);
});

test('normalise keeps list order and normalises elements', function (): void {
    expect(ToolArguments::normalise(['ids' => ['3', 'B', 1], 'm' => [['b' => 1, 'a' => 2]]]))
        ->toBe(['ids' => [3, 'b', 1], 'm' => [['a' => 2, 'b' => 1]]]);
});

test('same ignores key order, case, numeric form and nulls', function (): void {
    expect(ToolArguments::same(['title' => ' Dune ', 'year' => '2021', 'x' => null], ['year' => 2021, 'title' => 'dune']))->toBeTrue()
        ->and(ToolArguments::same(['id' => 5], ['id' => 6]))->toBeFalse()
        ->and(ToolArguments::same(['ids' => [1, 2]], ['ids' => [2, 1]]))->toBeFalse();
});

test('contains allows extra keys in actual, recursively', function (): void {
    $actual = ['a' => 1, 'opts' => ['x' => 'Y', 'z' => 3], 'extra' => true];

    expect(ToolArguments::contains($actual, ['opts' => ['x' => 'y']]))->toBeTrue()
        ->and(ToolArguments::contains($actual, ['a' => '1']))->toBeTrue()
        ->and(ToolArguments::contains($actual, []))->toBeTrue();
});

test('contains fails on a missing key or differing value', function (): void {
    expect(ToolArguments::contains(['a' => 1], ['b' => 1]))->toBeFalse()
        ->and(ToolArguments::contains(['a' => 1], ['a' => 2]))->toBeFalse()
        ->and(ToolArguments::contains(['o' => ['x' => 1]], ['o' => ['y' => 1]]))->toBeFalse();
});

test('contains requires lists to be equal', function (): void {
    expect(ToolArguments::contains(['ids' => [1, 2, 3]], ['ids' => [1, 2]]))->toBeFalse()
        ->and(ToolArguments::contains(['ids' => [1, 2]], ['ids' => ['1', '2']]))->toBeTrue();
});
