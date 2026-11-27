<?php

declare(strict_types=1);

use App\Models\User;

test('the notifications page reloads when a notification row is stored', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $webpage = visit(route('notifications.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(reloadRecorderScript());

    $webpage->script(echoEmitScript('App.Models.User.'.$user->id, 'UserNotificationStored', ['id' => 'abc']));
    $webpage->script(confirmDialogWaitUntilScript('window.__mmReloads.length > 0'));
    $webpage->wait(1.5);

    $reloads = $webpage->script('window.__mmReloads');
    expect($reloads)->toHaveCount(1)
        ->and(explode(',', (string) $reloads[0]))->toContain('notifications', 'unreadCount');
    $webpage->assertNoSmoke();
});
