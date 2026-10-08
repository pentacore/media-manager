<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\ServiceWarning;
use Illuminate\Support\Str;

test('the sidebar footer shows the app version and the logo shows only the host', function (): void {
    config()->set('app.version', '1.7.2');
    $this->actingAs(User::factory()->member()->create());

    visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-app-version]', 'v1.7.2')
        ->assertScript("document.querySelector('[data-app-logo-subtitle]').textContent.trim() === window.location.hostname");
});

test('the header badge shows the unread notification count from the shared nav prop', function (): void {
    $member = User::factory()->member()->create();

    // There is no DatabaseNotification factory; these rows mirror what
    // ServiceWarning::toArray() stores.
    foreach (range(1, 3) as $number) {
        $member->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => ServiceWarning::class,
            'data' => ['service' => 'sabnzbd', 'title' => sprintf('Disk warning %d', $number), 'message' => 'Less than 1 GB free', 'level' => 'disk_full'],
        ]);
    }

    $this->actingAs($member);

    visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-unread-notifications]', '3');
});
