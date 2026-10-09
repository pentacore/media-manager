<?php

declare(strict_types=1);

use App\Models\User;

test('without a connection an admin is sent to the connections page and a member is told to ask an admin', function (string $routeName, string $hook, string $service): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route($routeName, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn($hook, sprintf('No active %s connection.', $service))
        ->assertSeeIn($hook, 'Admin → Connections')
        ->assertDontSeeIn($hook, 'ask an admin');

    $this->actingAs(User::factory()->member()->create());

    visit(route($routeName, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn($hook, sprintf('No active %s connection — ask an admin to set one up.', $service))
        ->assertDontSeeIn($hook, 'Admin → Connections')
        ->assertMissing(sprintf('%s a', $hook));
})->with([
    'Indexer Search' => ['prowlarr.search', '[data-prowlarr-no-connection]', 'Prowlarr'],
    'Downloads' => ['sabnzbd.queue.index', '[data-sabnzbd-no-connection]', 'SABnzbd'],
]);
