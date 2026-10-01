<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;

/**
 * Delivers an ActivityLogCreated event on the admin-only audit channel
 * straight through the page's Pusher client (no Reverb in browser tests).
 */
function emitAuditRowCreated(): string
{
    return <<<'JS'
        (() => {
            const pusher = window.Pusher
                && window.Pusher.instances
                && window.Pusher.instances[0];
            if (!pusher) { return 'NO_PUSHER'; }
            const channel = pusher.channels.channels['private-activity.audit'];
            if (!channel) { return 'NO_CHANNEL'; }
            const bound = channel.callbacks._callbacks['_ActivityLogCreated'];
            if (!bound || bound.length === 0) { return 'NO_CALLBACK'; }
            channel.emit('ActivityLogCreated', {
                id: 999999, action: 'connection.updated', category: 'audit',
                description: 'Updated Radarr connection "Movies".', user_name: 'Ada Admin',
                service_id: null, service_name: null, service_type: null,
                subject_type: null, subject_id: null, metadata: null,
                created_at: new Date().toISOString(),
            });
            return 'EMITTED';
        })()
        JS;
}

test('an admin narrows the activity log to audit rows and expands the masked diff', function (): void {
    $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $this->actingAs($admin);

    ActivityLog::factory()->create(['action' => 'sabnzbd.queue.paused', 'description' => 'Paused the SABnzbd queue.']);
    resolve(AuditLogger::class)->record('connection.updated', null, 'Updated Sonarr connection "Main".', [
        'name' => ['from' => 'Sonarr', 'to' => 'Main'],
        'api_key' => ['from' => 'old-secret-key', 'to' => 'new-secret-key'],
    ]);

    visit(route('activity-log', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-activity-feed]', 'Paused the SABnzbd queue.')
        ->click('[data-category-option="audit"]')
        ->assertSeeIn('[data-activity-feed]', 'Updated Sonarr connection "Main".')
        ->assertCount('[data-activity-row]', 1)
        ->assertSeeIn('[data-audit-row]', 'Ada Admin')
        ->click('[data-audit-expand]')
        ->assertSeeIn('[data-audit-change="name"]', 'Main')
        ->assertSeeIn('[data-audit-change="api_key"]', 'changed (value not recorded)')
        ->assertDontSeeIn('[data-audit-changes]', 'new-secret-key')
        ->assertNoSmoke();
});

test('members see no audit filter and no audit rows', function (): void {
    $this->actingAs(User::factory()->member()->create());

    ActivityLog::factory()->create(['description' => 'Paused the SABnzbd queue.']);
    ActivityLog::factory()->audit()->create(['description' => 'Deleted Bob <bob@example.com>.']);

    visit(route('activity-log', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-activity-feed]', 'Paused the SABnzbd queue.')
        ->assertMissing('[data-audit-row]')
        ->assertMissing('[data-category-filter]');
});

test('changing a filter clears the pending new audit rows counter', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    ActivityLog::factory()->create(['description' => 'Paused the SABnzbd queue.']);

    $webpage = visit(route('activity-log', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-activity-feed]', 'Paused the SABnzbd queue.');

    expect($webpage->script(emitAuditRowCreated()))->toBe('EMITTED');

    $webpage->assertSeeIn('[data-activity-new-count]', '1 new')
        ->click('[data-category-option="audit"]')
        ->assertQueryStringHas('category', 'audit')
        ->assertMissing('[data-activity-new-count]')
        ->assertNoSmoke();
});
