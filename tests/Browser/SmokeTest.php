<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;

test('major pages render without JavaScript errors for a member', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member);

    $arrayablePendingAwaitablePage = visit([
        '/dashboard',
        '/activity-log',
        '/media/search',
        '/series',
        '/movies',
        '/requests',
        '/monitoring/now-playing',
        '/monitoring/watch-history',
        '/monitoring/service-health',
        '/prowlarr/search',
        '/actions/requests',
    ]);

    $arrayablePendingAwaitablePage->assertNoJavaScriptErrors();
});

test('admin-only pages render without JavaScript errors', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    $arrayablePendingAwaitablePage = visit([
        '/admin/connections',
        '/admin/users',
        '/actions/rules',
        '/emby/links',
    ]);

    $arrayablePendingAwaitablePage->assertNoJavaScriptErrors();
});

test('admin edit page for a Prowlarr connection renders without console errors', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'test',
    ]);

    $this->actingAs($admin);

    visit(sprintf('/admin/connections/%d/edit', $connection->id))->assertNoJavaScriptErrors();
});

test('viewer landing on dashboard sees no realtime auth errors in the console', function (): void {
    // Verifies the I1 fix: dashboard, activity, and emby.activity channels
    // are now open to all auth users, so a viewer's Echo subscriptions don't
    // 403 and surface as console errors.
    $viewer = User::factory()->create(); // default role is Viewer

    $this->actingAs($viewer);

    visit('/dashboard')
        ->assertNoJavaScriptErrors()
        ->assertSee('Dashboard');
});
