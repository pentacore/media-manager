<?php

declare(strict_types=1);

use App\Ai\Routing\ToolGroup;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Settings\AiSettings;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

test('lists every group with availability', function (): void {
    resolve(AiSettings::class)->setChatRoutingEnabled(true);

    $response = $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('ai.chat.tool-groups'))
        ->assertOk();

    $groups = collect($response->json('groups'))->keyBy('value');

    expect($response->json('routing_enabled'))->toBeTrue()
        ->and($groups->keys()->all())->toBe(array_map(fn (ToolGroup $toolGroup): string => $toolGroup->value, ToolGroup::cases()))
        ->and($groups['indexers'])->toBe(['value' => 'indexers', 'label' => 'Indexers', 'available' => false])
        ->and($groups['playback']['available'])->toBeTrue();
});

test('a group becomes available once its service is connected', function (): void {
    ServiceConnection::factory()->prowlarr()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('ai.chat.tool-groups'))
        ->assertOk()
        ->assertJsonPath('groups.6.available', true);
});

test('reports routing off', function (): void {
    resolve(AiSettings::class)->setChatRoutingEnabled(false);

    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('ai.chat.tool-groups'))
        ->assertOk()
        ->assertJsonPath('routing_enabled', false);
});

test('non-admins cannot read the tool groups', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson(route('ai.chat.tool-groups'))
        ->assertForbidden();
});
