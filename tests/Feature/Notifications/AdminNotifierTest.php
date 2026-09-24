<?php

declare(strict_types=1);

use App\Enums\NotificationSeverity;
use App\Models\NotificationDestination;
use App\Models\User;
use App\Notifications\ServiceWarning;
use App\Services\Notifications\AdminNotifier;
use Illuminate\Support\Facades\Notification;

test('send reaches every admin and every enabled destination, nobody else', function (): void {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $member = User::factory()->member()->create();
    $enabled = NotificationDestination::factory()->discord()->create();
    $disabled = NotificationDestination::factory()->discord()->disabled()->create();

    resolve(AdminNotifier::class)->send(new ServiceWarning('sonarr', 'T', 'M', 'error'));

    Notification::assertSentTo($admin, ServiceWarning::class);
    Notification::assertSentTo($enabled, ServiceWarning::class);
    Notification::assertNotSentTo($member, ServiceWarning::class);
    Notification::assertNotSentTo($disabled, ServiceWarning::class);
});

test('a destination below its severity threshold receives no channels', function (): void {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $destination = NotificationDestination::factory()->discord()->minSeverity(NotificationSeverity::Error)->create();

    resolve(AdminNotifier::class)->send(new ServiceWarning('sonarr', 'T', 'M', 'info'));

    // PreferenceResolver hands a below-threshold destination an empty channel
    // list, and NotificationFake records nothing for an empty channel list —
    // so "no channels" is observable only as "nothing delivered".
    Notification::assertNotSentTo($destination, ServiceWarning::class);
    Notification::assertSentTo($admin, ServiceWarning::class);
});

test('send is a no-op without admins or destinations', function (): void {
    Notification::fake();
    User::factory()->member()->create();

    resolve(AdminNotifier::class)->send(new ServiceWarning('sonarr', 'T', 'M', 'info'));

    Notification::assertNothingSent();
});

test('admins returns only admin users', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->member()->create();

    expect(resolve(AdminNotifier::class)->admins()->pluck('id')->all())->toBe([$admin->id]);
});

test('a destination is notified exactly once no matter how many admins exist', function (): void {
    Notification::fake();
    User::factory()->admin()->count(3)->create();
    $destination = NotificationDestination::factory()->discord()->create();

    resolve(AdminNotifier::class)->send(new ServiceWarning('sonarr', 'T', 'M', 'error'));

    Notification::assertSentToTimes($destination, ServiceWarning::class, 1);
});
