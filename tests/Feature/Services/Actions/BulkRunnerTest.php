<?php

declare(strict_types=1);

use App\Services\Actions\BulkItemOutcome;
use App\Services\Actions\BulkRunner;
use App\Services\Actions\BulkSummary;
use App\Services\Actions\ManualActionOutcome;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Exceptions;

test('every id runs in order and the outcomes are counted', function (): void {
    $seen = [];

    $bulkSummary = new BulkRunner()->run([1, 2, 3, 4], function (int $id) use (&$seen): BulkItemOutcome {
        $seen[] = $id;

        return match ($id) {
            1 => BulkItemOutcome::started(),
            2 => BulkItemOutcome::queued(),
            3 => BulkItemOutcome::skipped(),
            default => BulkItemOutcome::failed('Radarr is unreachable right now.'),
        };
    }, fn (int $id): string => sprintf('Title %d', $id));

    expect($seen)->toBe([1, 2, 3, 4])
        ->and($bulkSummary->toArray())->toBe([
            'started' => 1,
            'queued' => 1,
            'skipped' => 1,
            'failed' => [['id' => 4, 'title' => 'Title 4', 'reason' => 'Radarr is unreachable right now.']],
        ]);
});

test('an unexpected exception fails only its own item, is reported and never echoes its message', function (): void {
    Exceptions::fake();

    $bulkSummary = new BulkRunner()->run([1, 2, 3], function (int $id): BulkItemOutcome {
        throw_if($id === 2, RuntimeException::class, 'upstream body /data/secret');

        return BulkItemOutcome::started();
    }, fn (int $id): string => sprintf('#%d', $id));

    expect($bulkSummary->started)->toBe(2)
        ->and($bulkSummary->failed)->toBe([['id' => 2, 'title' => '#2', 'reason' => 'Something went wrong with this item — check the logs.']]);

    Exceptions::assertReported(RuntimeException::class);
});

test('titles are looked up only for failed items', function (): void {
    $lookups = [];

    new BulkRunner()->run(
        ['a', 'b'],
        fn (string $id): BulkItemOutcome => $id === 'a' ? BulkItemOutcome::started() : BulkItemOutcome::failed('No.'),
        function (string $id) use (&$lookups): string {
            $lookups[] = $id;

            return 'Title';
        },
    );

    expect($lookups)->toBe(['b']);
});

test('more than 100 ids are refused before anything runs', function (): void {
    $ran = false;

    expect(fn (): BulkSummary => new BulkRunner()->run(range(1, 101), function () use (&$ran): BulkItemOutcome {
        $ran = true;

        return BulkItemOutcome::started();
    }, fn (): string => ''))->toThrow(InvalidArgumentException::class);

    expect($ran)->toBeFalse();
});

test('a repeated id is refused before anything runs', function (array $ids): void {
    $ran = false;

    expect(fn (): BulkSummary => new BulkRunner()->run($ids, function () use (&$ran): BulkItemOutcome {
        $ran = true;

        return BulkItemOutcome::started();
    }, fn (): string => ''))->toThrow(InvalidArgumentException::class, 'A bulk action takes each id once.');

    expect($ran)->toBeFalse();
})->with([
    'integers' => [[1, 2, 1]],
    'strings' => [['a', 'b', 'a']],
    'an integer and its numeric string' => [[7, '7']],
]);

test('once the time budget is spent the remaining ids fail without being attempted or looked up', function (): void {
    $attempted = [];
    $lookedUp = [];

    $bulkSummary = new BulkRunner(budgetSeconds: 100)->run([1, 2, 3, 4], function (int $id) use (&$attempted): BulkItemOutcome {
        $attempted[] = $id;
        // Each item takes 60 seconds of (faked) wall-clock time.
        $this->travel(60)->seconds();

        return BulkItemOutcome::started();
    }, function (int $id) use (&$lookedUp): string {
        $lookedUp[] = $id;

        return sprintf('Title %d', $id);
    });

    expect($attempted)->toBe([1, 2])
        ->and($lookedUp)->toBe([])
        ->and($bulkSummary->started)->toBe(2)
        ->and($bulkSummary->failed)->toBe([
            ['id' => 3, 'title' => '#3', 'reason' => 'Not attempted — the batch ran out of time.'],
            ['id' => 4, 'title' => '#4', 'reason' => 'Not attempted — the batch ran out of time.'],
        ]);
});

test('a failing title lookup is reported and falls back to the id instead of failing the batch', function (): void {
    Exceptions::fake();

    $bulkSummary = new BulkRunner()->run(
        [1, 'b', 3],
        fn (int|string $id): BulkItemOutcome => $id === 3 ? BulkItemOutcome::started() : BulkItemOutcome::failed('No.'),
        function (): string {
            throw new TypeError('malformed upstream payload');
        },
    );

    expect($bulkSummary->started)->toBe(1)
        ->and($bulkSummary->failed)->toBe([
            ['id' => 1, 'title' => '#1', 'reason' => 'No.'],
            ['id' => 'b', 'title' => 'b', 'reason' => 'No.'],
        ]);

    Exceptions::assertReported(TypeError::class);
});

test('manual action outcomes map onto bulk outcomes with the single action wording', function (string $state, string $bulkState, ?string $reason): void {
    $bulkItemOutcome = BulkItemOutcome::fromManualAction(new ManualActionOutcome(state: $state));

    expect($bulkItemOutcome->toArray())->toBe(['state' => $bulkState, 'reason' => $reason]);
})->with([
    'started' => [ManualActionOutcome::STARTED, BulkItemOutcome::STARTED, null],
    'queued' => [ManualActionOutcome::QUEUED, BulkItemOutcome::QUEUED, null],
    'disabled' => [ManualActionOutcome::DISABLED, BulkItemOutcome::FAILED, 'This action is disabled in Action Rules.'],
    'undescribable' => [ManualActionOutcome::UNDESCRIBABLE, BulkItemOutcome::FAILED, 'That item could not be found — refresh and try again.'],
    'blocked' => [ManualActionOutcome::BLOCKED, BulkItemOutcome::FAILED, 'A file replacement is in progress for this title — try again when it finishes.'],
]);

test('upstream failures read as an outage or a refusal, never as the upstream body', function (): void {
    $refusal = new RequestException(new Response(new Psr7Response(404, [], 'secret body')));
    $outage = new RequestException(new Response(new Psr7Response(503, [], 'secret body')));

    expect(BulkItemOutcome::fromUpstreamFailure($refusal, 'Radarr')->reason)->toBe('Radarr refused the change.')
        ->and(BulkItemOutcome::fromUpstreamFailure($outage, 'Radarr')->reason)->toBe('Radarr is unreachable right now.')
        ->and(BulkItemOutcome::fromUpstreamFailure(new ConnectionException('refused'), 'SABnzbd')->reason)->toBe('SABnzbd is unreachable right now.');
});

test('the summary toast reads like the spec example', function (): void {
    $bulkSummary = new BulkSummary(started: 3, queued: 12, skipped: 0, failed: [
        ['id' => 9, 'title' => 'Dune (2021)', 'reason' => 'Radarr is unreachable right now.'],
    ]);

    expect($bulkSummary->toast())->toBe([
        'type' => 'error',
        'message' => '12 queued for approval, 3 started, 1 failed: Dune (2021) — Radarr is unreachable right now.',
    ]);
});

test('the toast names the verb, counts skips and summarises further failures', function (): void {
    $bulkSummary = new BulkSummary(started: 2, queued: 0, skipped: 1, failed: [
        ['id' => 1, 'title' => 'A', 'reason' => 'No.'],
        ['id' => 2, 'title' => 'B', 'reason' => 'No.'],
    ]);

    expect($bulkSummary->toast('rejected')['message'])->toBe('2 rejected, 1 skipped, 2 failed: A — No. (+1 more)');
});

test('a clean run is a success, an all-queued run is info', function (): void {
    expect(new BulkSummary(started: 3, queued: 0, skipped: 0, failed: [])->toast())->toBe(['type' => 'success', 'message' => '3 started'])
        ->and(new BulkSummary(started: 0, queued: 2, skipped: 0, failed: [])->toast())->toBe(['type' => 'info', 'message' => '2 queued for approval'])
        ->and(new BulkSummary(started: 0, queued: 0, skipped: 2, failed: [])->toast())->toBe(['type' => 'info', 'message' => '2 skipped'])
        ->and(new BulkSummary(started: 1, queued: 0, skipped: 0, failed: [])->withToast('paused'))->toBe([
            'started' => 1, 'queued' => 0, 'skipped' => 0, 'failed' => [],
            'toast' => ['type' => 'success', 'message' => '1 paused'],
        ]);
});
