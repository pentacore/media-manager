<?php

declare(strict_types=1);

use App\Enums\ActivityLogCategory;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

function auditStorageUser(string $role): User
{
    return match ($role) {
        'admin' => User::factory()->admin()->create(),
        'member' => User::factory()->member()->create(),
        default => User::factory()->create(),
    };
}

test('rows written without a category are ordinary activity and the category is indexed', function (): void {
    $activityLog = ActivityLog::create(['action' => 'sabnzbd.queue.paused', 'description' => 'Paused the SABnzbd queue.']);

    expect($activityLog->fresh()->category)->toBe(ActivityLogCategory::Activity)
        ->and($activityLog->fresh()->isAudit())->toBeFalse()
        ->and(Schema::hasIndex('activity_logs', ['category', 'created_at']))->toBeTrue();
});

test('the audit factory state writes an audit row', function (): void {
    expect(ActivityLog::factory()->audit()->create()->fresh()->isAudit())->toBeTrue();
});

test('visibleTo hides audit rows from everyone but admins', function (string $role, int $expected): void {
    ActivityLog::factory()->create();
    ActivityLog::factory()->audit()->create();

    expect(ActivityLog::query()->visibleTo(auditStorageUser($role))->count())->toBe($expected);
})->with([
    'viewer' => ['viewer', 0],
    'member' => ['member', 1],
    'admin' => ['admin', 2],
]);

test('visibleTo without a user never returns audit rows', function (): void {
    $activity = ActivityLog::factory()->create();
    ActivityLog::factory()->audit()->create();

    expect(ActivityLog::query()->visibleTo(null)->pluck('id')->all())->toBe([$activity->id]);
});

test('retention prunes activity and audit rows on their own windows', function (): void {
    config()->set('mediamanager.retention.activity_logs_days', 180);
    config()->set('mediamanager.retention.audit_logs_days', 365);

    $oldActivity = ActivityLog::factory()->create();
    $oldAudit = ActivityLog::factory()->audit()->create();
    $expiredAudit = ActivityLog::factory()->audit()->create();
    $freshActivity = ActivityLog::factory()->create();
    ActivityLog::query()->whereKey([$oldActivity->id, $oldAudit->id])->update(['created_at' => now()->subDays(200)]);
    ActivityLog::query()->whereKey($expiredAudit->id)->update(['created_at' => now()->subDays(400)]);

    $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

    expect(ActivityLog::query()->orderBy('id')->pluck('id')->all())->toBe([$oldAudit->id, $freshActivity->id]);
});

test('an audit retention of zero keeps audit rows forever while activity still prunes', function (): void {
    config()->set('mediamanager.retention.activity_logs_days', 30);
    config()->set('mediamanager.retention.audit_logs_days', 0);

    $activity = ActivityLog::factory()->create();
    $audit = ActivityLog::factory()->audit()->create();
    ActivityLog::query()->whereKey([$activity->id, $audit->id])->update(['created_at' => now()->subYears(5)]);

    $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

    expect(ActivityLog::query()->pluck('id')->all())->toBe([$audit->id]);
});

test('with both retention windows at zero nothing is pruned', function (): void {
    config()->set('mediamanager.retention.activity_logs_days', 0);
    config()->set('mediamanager.retention.audit_logs_days', 0);

    ActivityLog::factory()->create();
    ActivityLog::factory()->audit()->create();
    ActivityLog::query()->update(['created_at' => now()->subYears(5)]);

    $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

    expect(ActivityLog::query()->count())->toBe(2);
});

test('the audit retention window defaults to a year', function (): void {
    expect(config('mediamanager.retention.audit_logs_days'))->toBe(365);
});
