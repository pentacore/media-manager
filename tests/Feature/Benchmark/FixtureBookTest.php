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
    $fixtureBook = fixtureBookFor();

    expect($fixtureBook->answer('search', ['query' => ' Dune ']))->toBe('R1')
        ->and($fixtureBook->answer('lookup', ['id' => '5']))->toBe('R2')
        ->and($fixtureBook->calls())->toBe(2)
        ->and($fixtureBook->misses())->toBe(0);
});

test('answer returns null and counts a miss for unrecorded calls', function (): void {
    $fixtureBook = fixtureBookFor();

    expect($fixtureBook->answer('search', ['query' => 'other']))->toBeNull()
        ->and($fixtureBook->answer('lookup', ['query' => 'dune']))->toBeNull()
        ->and($fixtureBook->calls())->toBe(2)
        ->and($fixtureBook->misses())->toBe(2);
});

test('a repeated call hits the same fixture again', function (): void {
    $fixtureBook = fixtureBookFor();

    expect($fixtureBook->answer('lookup', ['id' => 5]))->toBe('R2')
        ->and($fixtureBook->answer('lookup', ['id' => 5]))->toBe('R2')
        ->and($fixtureBook->misses())->toBe(0);
});

test('peek looks up without counting or logging', function (): void {
    $fixtureBook = fixtureBookFor();

    expect($fixtureBook->peek('lookup', ['id' => 5]))->toBe('R2')
        ->and($fixtureBook->peek('lookup', ['id' => 9]))->toBeNull()
        ->and($fixtureBook->calls())->toBe(0)
        ->and($fixtureBook->misses())->toBe(0)
        ->and($fixtureBook->log())->toBe([]);
});

test('intend records each write call as an intended action', function (): void {
    $fixtureBook = fixtureBookFor();
    $fixtureBook->intend('add_movie', ['tmdb_id' => 1]);
    $fixtureBook->intend('add_movie', ['tmdb_id' => 1]);

    expect($fixtureBook->intendedActions())->toBe([
        ['tool' => 'add_movie', 'arguments' => ['tmdb_id' => 1]],
        ['tool' => 'add_movie', 'arguments' => ['tmdb_id' => 1]],
    ])->and($fixtureBook->calls())->toBe(0);
});

test('log lists every answer call in order', function (): void {
    $fixtureBook = fixtureBookFor();
    $fixtureBook->answer('search', ['query' => 'dune']);
    $fixtureBook->answer('search', ['query' => 'nope']);

    expect($fixtureBook->log())->toBe([
        ['tool' => 'search', 'arguments' => ['query' => 'dune'], 'result' => 'R1', 'hit' => true],
        ['tool' => 'search', 'arguments' => ['query' => 'nope'], 'result' => null, 'hit' => false],
    ]);
});

test('a recorded write joins the log in call order as a call but never a miss', function (): void {
    $fixtureBook = fixtureBookFor();
    $fixtureBook->answer('search', ['query' => 'dune']);
    $fixtureBook->recordWrite('delete', ['id' => 3], '{"queued":true}');
    $fixtureBook->answer('lookup', ['id' => 5]);

    expect($fixtureBook->log())->toBe([
        ['tool' => 'search', 'arguments' => ['query' => 'dune'], 'result' => 'R1', 'hit' => true],
        ['tool' => 'delete', 'arguments' => ['id' => 3], 'result' => '{"queued":true}', 'hit' => true, 'write' => true],
        ['tool' => 'lookup', 'arguments' => ['id' => 5], 'result' => 'R2', 'hit' => true],
    ])
        ->and($fixtureBook->calls())->toBe(3)
        ->and($fixtureBook->misses())->toBe(0)
        ->and($fixtureBook->intendedActions())->toBe([]);
});

test('a refused write is logged as a refused call but never an intended action', function (): void {
    $fixtureBook = fixtureBookFor();
    $fixtureBook->recordRefusal('delete', ['id' => 3], '{"error":"advisory_mode_blocks_destructive"}');

    expect($fixtureBook->log())->toBe([
        ['tool' => 'delete', 'arguments' => ['id' => 3], 'result' => '{"error":"advisory_mode_blocks_destructive"}', 'hit' => true, 'write' => true, 'refused' => true],
    ])
        ->and($fixtureBook->calls())->toBe(1)
        ->and($fixtureBook->misses())->toBe(0)
        ->and($fixtureBook->intendedActions())->toBe([]);
});

test('the miss result constant is the not_recorded error', function (): void {
    expect(json_decode(FixtureBook::MISS_RESULT, true))->toBe([
        'error' => 'not_recorded',
        'message' => 'Not available in this recorded case. Try a different approach or answer with what you have.',
    ]);
});
