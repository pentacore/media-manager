<?php

declare(strict_types=1);

use App\Ai\Benchmark\FixtureBook;

function fixtureBookFor(): FixtureBook
{
    return new FixtureBook([
        ['tool' => 'search', 'arguments' => ['query' => 'dune'], 'result' => 'R1'],
        ['tool' => 'search', 'arguments' => ['query' => 'dune'], 'result' => 'R-shadowed'],
        ['tool' => 'lookup', 'arguments' => ['id' => 5], 'result' => 'R2'],
    ]);
}

test('answer returns the recorded result for a normalised match', function (): void {
    $book = fixtureBookFor();

    expect($book->answer('search', ['query' => ' Dune ']))->toBe('R1')
        ->and($book->answer('lookup', ['id' => '5']))->toBe('R2')
        ->and($book->calls())->toBe(2)
        ->and($book->misses())->toBe(0);
});

test('answer returns null and counts a miss for unrecorded calls', function (): void {
    $book = fixtureBookFor();

    expect($book->answer('search', ['query' => 'other']))->toBeNull()
        ->and($book->answer('lookup', ['query' => 'dune']))->toBeNull()
        ->and($book->calls())->toBe(2)
        ->and($book->misses())->toBe(2);
});

test('a repeated call hits the same fixture again', function (): void {
    $book = fixtureBookFor();

    expect($book->answer('lookup', ['id' => 5]))->toBe('R2')
        ->and($book->answer('lookup', ['id' => 5]))->toBe('R2')
        ->and($book->misses())->toBe(0);
});

test('peek looks up without counting or logging', function (): void {
    $book = fixtureBookFor();

    expect($book->peek('lookup', ['id' => 5]))->toBe('R2')
        ->and($book->peek('lookup', ['id' => 9]))->toBeNull()
        ->and($book->calls())->toBe(0)
        ->and($book->misses())->toBe(0)
        ->and($book->log())->toBe([]);
});

test('intend records each write call as an intended action', function (): void {
    $book = fixtureBookFor();
    $book->intend('add_movie', ['tmdb_id' => 1]);
    $book->intend('add_movie', ['tmdb_id' => 1]);

    expect($book->intendedActions())->toBe([
        ['tool' => 'add_movie', 'arguments' => ['tmdb_id' => 1]],
        ['tool' => 'add_movie', 'arguments' => ['tmdb_id' => 1]],
    ])->and($book->calls())->toBe(0);
});

test('log lists every answer call in order', function (): void {
    $book = fixtureBookFor();
    $book->answer('search', ['query' => 'dune']);
    $book->answer('search', ['query' => 'nope']);

    expect($book->log())->toBe([
        ['tool' => 'search', 'arguments' => ['query' => 'dune'], 'result' => 'R1', 'hit' => true],
        ['tool' => 'search', 'arguments' => ['query' => 'nope'], 'result' => null, 'hit' => false],
    ]);
});

test('the miss result constant is the not_recorded error', function (): void {
    expect(json_decode(FixtureBook::MISS_RESULT, true))->toBe([
        'error' => 'not_recorded',
        'message' => 'Not available in this recorded case. Try a different approach or answer with what you have.',
    ]);
});
