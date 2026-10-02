<?php

declare(strict_types=1);

use App\Ai\Tools\System\QueryActivityTool;
use App\Events\ActivityLogCreated;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
});

function auditVisibilityUser(string $role): User
{
    return match ($role) {
        'admin' => User::factory()->admin()->create(),
        'member' => User::factory()->member()->create(),
        default => User::factory()->create(),
    };
}

/**
 * @return array{0: ActivityLog, 1: ActivityLog}
 */
function auditVisibilityRows(): array
{
    return [
        ActivityLog::factory()->create(['action' => 'sabnzbd.queue.paused', 'description' => 'Paused the SABnzbd queue.']),
        ActivityLog::factory()->audit()->create(['action' => 'connection.updated', 'description' => 'Updated Sonarr connection "Main".']),
    ];
}

test('viewers cannot open the activity log or its export at all', function (): void {
    auditVisibilityRows();
    $user = auditVisibilityUser('viewer');

    $this->actingAs($user)->get(route('activity-log'))->assertForbidden();
    $this->actingAs($user)->get(route('activity-log.export', ['category' => 'audit']))->assertForbidden();
});

test('the activity log page never lists audit rows for members', function (string $role): void {
    [$activity] = auditVisibilityRows();

    $this->actingAs(auditVisibilityUser($role))
        ->get(route('activity-log'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ActivityLog')
            ->has('logs.data', 1)
            ->where('logs.data.0.id', $activity->id)
            ->where('logs.data.0.category', 'activity')
            ->where('filterOptions.actions', ['sabnzbd.queue.paused'])
            ->where('filterOptions.categories', [])
            ->where('filters.category', null));
})->with(['member']);

test('asking for the audit category as a non-admin returns only activity rows, on the page and in the export', function (string $role): void {
    [$activity, $audit] = auditVisibilityRows();
    $user = auditVisibilityUser($role);

    $this->actingAs($user)
        ->get(route('activity-log', ['category' => 'audit']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.id', $activity->id)
            ->where('filters.category', null)
            ->where('filterOptions.categories', []));

    $export = $this->actingAs($user)->get(route('activity-log.export', ['category' => 'audit']))->streamedContent();

    expect($export)->toContain('"id":'.$activity->id)
        ->not->toContain('connection.updated')
        ->not->toContain('"id":'.$audit->id.',');
})->with(['member']);

test('admins see audit rows and can narrow the page and the export to them', function (): void {
    [$activity, $audit] = auditVisibilityRows();
    $user = auditVisibilityUser('admin');

    $this->actingAs($user)
        ->get(route('activity-log'))
        ->assertInertia(fn ($page) => $page
            ->has('logs.data', 2)
            ->where('filterOptions.categories', ['activity', 'audit']));

    $this->actingAs($user)
        ->get(route('activity-log', ['category' => 'audit']))
        ->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.id', $audit->id)
            ->where('logs.data.0.category', 'audit')
            ->where('filters.category', 'audit'));

    $export = $this->actingAs($user)->get(route('activity-log.export', ['category' => 'audit']))->streamedContent();

    expect($export)->toContain('"category":"audit"')
        ->toContain('connection.updated')
        ->not->toContain('sabnzbd.queue.paused');
});

test('the dashboard recent activity shows audit rows to admins only', function (string $role, int $expected): void {
    auditVisibilityRows();

    $this->actingAs(auditVisibilityUser($role))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('recentActivity', $expected));
})->with([
    'viewer' => ['viewer', 0],
    'member' => ['member', 1],
    'admin' => ['admin', 2],
]);

test('the AI activity tool never returns audit rows', function (): void {
    [$activity] = auditVisibilityRows();
    $this->actingAs(auditVisibilityUser('admin'));

    $result = json_decode((new QueryActivityTool)->handle(new Request(['scope' => 'system', 'since_days' => null, 'media_type' => null, 'limit' => null])), true);

    expect(array_column($result['entries'], 'id'))->toBe([$activity->id]);
});

test('audit rows broadcast only on the admin audit channel', function (): void {
    [$activity, $audit] = auditVisibilityRows();

    $auditEvent = new ActivityLogCreated($audit);
    $activityEvent = new ActivityLogCreated($activity);

    expect($auditEvent->broadcastOn())->toEqual(new PrivateChannel('activity.audit'))
        ->and($auditEvent->broadcastWith()['category'])->toBe('audit')
        ->and($activityEvent->broadcastOn())->toEqual(new PrivateChannel('activity'))
        ->and($activityEvent->broadcastWith()['category'])->toBe('activity');
});
