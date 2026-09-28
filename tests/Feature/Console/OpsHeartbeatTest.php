<?php

declare(strict_types=1);

use App\Enums\QueueLane;
use App\Jobs\RecordQueueHeartbeat;
use App\Support\OpsHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

test('ops:record-heartbeat records the scheduler and queues one heartbeat per lane on that lane', function (): void {
    Queue::fake();
    $this->freezeTime();

    $this->artisan('ops:record-heartbeat')->assertSuccessful();

    expect(OpsHeartbeat::ageInSeconds(OpsHeartbeat::SCHEDULER))->toBe(0);
    foreach (QueueLane::cases() as $queueLane) {
        Queue::assertPushedOn($queueLane, RecordQueueHeartbeat::class, fn (RecordQueueHeartbeat $recordQueueHeartbeat): bool => $recordQueueHeartbeat->queueLane === $queueLane);
    }

    Queue::assertPushed(RecordQueueHeartbeat::class, count(QueueLane::cases()));
});

test('a processed queue heartbeat records its own lane only', function (): void {
    $this->freezeTime();

    new RecordQueueHeartbeat(QueueLane::Webhooks)->handle();
    $this->travel(90)->seconds();

    expect(OpsHeartbeat::ageInSeconds('queue:webhooks'))->toBe(90)
        ->and(OpsHeartbeat::ageInSeconds('queue:ai'))->toBeNull();
});

test('a heartbeat read back as a numeric string, as the Redis store returns it, still has an age', function (): void {
    $this->freezeTime();
    Cache::forever(OpsHeartbeat::CACHE_PREFIX.OpsHeartbeat::SCHEDULER, (string) (now()->getTimestamp() - 30));

    expect(OpsHeartbeat::ageInSeconds(OpsHeartbeat::SCHEDULER))->toBe(30);
});

test('ops:check-heartbeat passes on fresh heartbeats and fails on stale or missing ones', function (): void {
    $this->freezeTime();
    OpsHeartbeat::record(OpsHeartbeat::SCHEDULER);
    OpsHeartbeat::record('queue:actions');
    $this->travel(100)->seconds();
    OpsHeartbeat::record('queue:webhooks');

    $this->artisan('ops:check-heartbeat', ['--scheduler' => true, '--max-age' => 180])->assertSuccessful();
    $this->artisan('ops:check-heartbeat', ['--queue' => 'actions,webhooks', '--max-age' => 60])
        ->expectsOutputToContain('queue:actions: last heartbeat 100s ago')
        ->assertFailed();
    $this->artisan('ops:check-heartbeat', ['--queue' => 'default', '--max-age' => 600])
        ->expectsOutputToContain('queue:default: no heartbeat recorded')
        ->assertFailed();
});

test('ops:check-heartbeat rejects unknown lanes and an empty selection', function (): void {
    $this->artisan('ops:check-heartbeat', ['--queue' => 'bogus'])
        ->expectsOutputToContain('Unknown queue lane(s): bogus')
        ->assertFailed();
    $this->artisan('ops:check-heartbeat')->assertFailed();
});

test('the heartbeat is recorded every minute', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'ops:record-heartbeat'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});
