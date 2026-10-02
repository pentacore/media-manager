<?php

declare(strict_types=1);

use App\Events\ActivityLogCreated;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

test('creating an ActivityLog dispatches ActivityLogCreated', function (): void {
    Event::fake([ActivityLogCreated::class]);

    $log = ActivityLog::factory()->create();

    Event::assertDispatched(fn (ActivityLogCreated $activityLogCreated): bool => $activityLogCreated->activityLog->is($log));
});

test('updating an ActivityLog does not re-dispatch the created event', function (): void {
    $log = ActivityLog::factory()->create();

    Event::fake([ActivityLogCreated::class]);

    $log->update(['description' => 'changed']);

    Event::assertNotDispatched(ActivityLogCreated::class);
});

test('an activity row written inside a rolled-back transaction is never broadcast', function (): void {
    Event::fake([ActivityLogCreated::class]);

    expect(fn () => DB::transaction(function (): void {
        ActivityLog::factory()->create();

        throw new RuntimeException('roll back');
    }))->toThrow(RuntimeException::class, 'roll back');

    Event::assertNotDispatched(ActivityLogCreated::class);
});

test('an activity row written inside a committed transaction is broadcast after the commit', function (): void {
    Event::fake([ActivityLogCreated::class]);
    $holder = new stdClass;

    DB::transaction(function () use ($holder): void {
        $holder->log = ActivityLog::factory()->create();
        Event::assertNotDispatched(ActivityLogCreated::class);
    });

    Event::assertDispatched(fn (ActivityLogCreated $activityLogCreated): bool => $activityLogCreated->activityLog->is($holder->log));
});
