<?php

declare(strict_types=1);

use App\Models\ServiceConnection;

test('a connection without the setting gets two subtitle audit search slots', function (): void {
    expect(ServiceConnection::factory()->sonarr()->create(['settings' => null])->subtitleAuditSearchSlots())->toBe(2)
        ->and(ServiceConnection::factory()->sonarr()->create(['settings' => []])->subtitleAuditSearchSlots())->toBe(2);
});

test('the subtitle audit search slots are clamped to one through ten', function (mixed $stored, int $expected): void {
    $serviceConnection = ServiceConnection::factory()->sonarr()->create([
        'settings' => ['subtitle_audit' => ['search_slots' => $stored]],
    ]);

    expect($serviceConnection->subtitleAuditSearchSlots())->toBe($expected);
})->with([
    'a valid value' => [4, 4],
    'below one' => [0, 1],
    'above ten' => [25, 10],
    'not an integer' => ['3', 2],
    'null' => [null, 2],
]);

test('a malformed subtitle audit block falls back to two slots', function (): void {
    $serviceConnection = ServiceConnection::factory()->sonarr()->create([
        'settings' => ['subtitle_audit' => 'nope'],
    ]);

    expect($serviceConnection->subtitleAuditSearchSlots())->toBe(2);
});
