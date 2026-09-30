<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;

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
