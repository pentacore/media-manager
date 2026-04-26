<?php

declare(strict_types=1);

use App\Events\ActivityLogCreated;
use App\Models\ActivityLog;
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
