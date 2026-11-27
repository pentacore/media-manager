<?php

declare(strict_types=1);

use App\Models\User;

test('the service worker is a public static file with a push handler and no fetch handler', function (): void {
    // public/ files are served by the web server, not the Laravel router.
    expect(public_path('sw.js'))->toBeFile();
    expect(file_get_contents(public_path('sw.js')))->toContain("addEventListener('push'")->not->toContain("addEventListener('fetch'");
});

test('the service worker uses a dedicated monochrome badge and renotifies tagged pushes', function (): void {
    $worker = (string) file_get_contents(public_path('sw.js'));

    expect($worker)->toContain("badge: '/icons/badge-96.png'")
        ->toContain('renotify: Boolean(payload.tag)')
        ->and(public_path('icons/badge-96.png'))->toBeFile();

    [$width, $height, $type] = getimagesize(public_path('icons/badge-96.png'));
    expect([$width, $height, $type])->toBe([96, 96, IMAGETYPE_PNG]);
});

test('the manifest is standalone and its icons exist on disk', function (): void {
    expect(public_path('manifest.webmanifest'))->toBeFile();

    $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['display'])->toBe('standalone')
        ->and($manifest['icons'])->toHaveCount(3);

    foreach ($manifest['icons'] as $icon) {
        expect(public_path(ltrim((string) $icon['src'], '/')))->toBeFile();
    }
});

test('the app layout links the manifest and sets a theme color', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.notifications.edit'))
        ->assertOk()
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
        ->assertSee('<meta name="theme-color"', false);
});
