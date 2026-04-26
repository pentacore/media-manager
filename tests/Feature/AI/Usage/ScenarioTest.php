<?php

declare(strict_types=1);

use App\Services\AiUsage\Scenario;

test('fromArray returns scenario when all keys present and numeric', function (): void {
    $scenario = Scenario::fromArray([
        'input' => '0.40',
        'output' => '1.60',
        'cache_read' => '0.10',
        'cache_write' => '0.40',
        'reasoning' => '1.60',
    ]);

    expect($scenario)->not->toBeNull();
    expect($scenario->inputPerMtok)->toBe(0.40);
    expect($scenario->outputPerMtok)->toBe(1.60);
    expect($scenario->cacheReadPerMtok)->toBe(0.10);
    expect($scenario->cacheWritePerMtok)->toBe(0.40);
    expect($scenario->reasoningPerMtok)->toBe(1.60);
});

test('fromArray returns null when a required key is missing', function (): void {
    $scenario = Scenario::fromArray([
        'input' => 0.40,
        'output' => 1.60,
        // missing cache_read
        'cache_write' => 0.40,
        'reasoning' => 1.60,
    ]);

    expect($scenario)->toBeNull();
});

test('fromArray returns null when a value is not numeric', function (): void {
    $scenario = Scenario::fromArray([
        'input' => 'cheap',
        'output' => 1.60,
        'cache_read' => 0.10,
        'cache_write' => 0.40,
        'reasoning' => 1.60,
    ]);

    expect($scenario)->toBeNull();
});

test('fromArray returns null when a value is negative', function (): void {
    $scenario = Scenario::fromArray([
        'input' => -1,
        'output' => 1.60,
        'cache_read' => 0.10,
        'cache_write' => 0.40,
        'reasoning' => 1.60,
    ]);

    expect($scenario)->toBeNull();
});

test('toArray round-trips through fromArray', function (): void {
    $original = new Scenario(0.5, 2.0, 0.05, 0.5, 2.0);

    $rebuilt = Scenario::fromArray($original->toArray());

    expect($rebuilt)->not->toBeNull();
    expect($rebuilt->toArray())->toBe($original->toArray());
});

test('zero rates are accepted', function (): void {
    $scenario = Scenario::fromArray([
        'input' => 0,
        'output' => 0,
        'cache_read' => 0,
        'cache_write' => 0,
        'reasoning' => 0,
    ]);

    expect($scenario)->not->toBeNull();
    expect($scenario->inputPerMtok)->toBe(0.0);
});
