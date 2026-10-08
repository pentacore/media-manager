<?php

declare(strict_types=1);

use App\Ai\OpenRouterRequestOptions;
use App\Enums\OpenRouterSort;
use App\Models\User;
use App\Settings\OpenRouterSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
});

function providerSelectionPayload(array $overrides = []): array
{
    return [
        'mode' => 'executive',
        ...$overrides,
    ];
}

test('index exposes the OpenRouter preferences and no model selections', function (): void {
    resolve(OpenRouterSettings::class)->setOrder(['anthropic', 'amazon-bedrock']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiSettings/Index')
            ->where('settings.openrouter.order', 'anthropic, amazon-bedrock')
            ->where('settings.openrouter.allow_fallbacks', true)
            ->has('openRouterSorts', 3)
            ->missing('settings.model_provider')
            ->missing('settings.failover_model')
            ->missing('models')
            ->missing('failoverProviders'));
});

test('admin can save the OpenRouter routing preferences', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), providerSelectionPayload([
            'openrouter_sort' => 'latency',
            'openrouter_deny_data_collection' => '1',
            'openrouter_allow_fallbacks' => '0',
            'openrouter_order' => ' Anthropic ,, amazon-bedrock ,anthropic',
            'openrouter_ignore' => '',
        ]))
        ->assertRedirect();

    $openRouterSettings = resolve(OpenRouterSettings::class);

    expect($openRouterSettings->sort())->toBe(OpenRouterSort::Latency)
        ->and($openRouterSettings->denyDataCollection())->toBeTrue()
        ->and($openRouterSettings->allowFallbacks())->toBeFalse()
        ->and($openRouterSettings->order())->toBe(['anthropic', 'amazon-bedrock'])
        ->and($openRouterSettings->ignore())->toBe([]);
});

test('an invalid OpenRouter provider slug list is rejected', function (string $value): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), providerSelectionPayload([
            'openrouter_order' => $value,
        ]))
        ->assertSessionHasErrors('openrouter_order');
})->with([
    'anthropic, bad slug!',
    'amazon bedrock',
    str_repeat('a', 65),
]);

test('a valid OpenRouter provider slug list is accepted', function (string $value): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), providerSelectionPayload([
            'openrouter_order' => $value,
        ]))
        ->assertSessionDoesntHaveErrors('openrouter_order');
})->with([
    ' Anthropic ,, amazon-bedrock ,anthropic',
    'deepinfra/turbo',
    'google-vertex.eu',
    '',
]);

test('the OpenRouter ignore list is validated the same way as the order list', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), providerSelectionPayload([
            'openrouter_ignore' => 'amazon bedrock',
        ]))
        ->assertSessionHasErrors('openrouter_ignore');
});

test('an unknown OpenRouter sort is rejected', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), providerSelectionPayload(['openrouter_sort' => 'fastest']))
        ->assertSessionHasErrors('openrouter_sort');
});

test('a default OpenRouter sort clears the saved sort even when it must override a configured env default', function (): void {
    config()->set('mediamanager.ai.openrouter.sort', 'price');
    resolve(OpenRouterSettings::class)->setSort(OpenRouterSort::Latency);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), providerSelectionPayload(['openrouter_sort' => 'default']))
        ->assertRedirect();

    expect(resolve(OpenRouterSettings::class)->sort())->toBeNull()
        ->and(resolve(OpenRouterRequestOptions::class)->routing())->toBe([]);
});

test('setSort(null) still falls back to the configured env default, unlike choosing default explicitly', function (): void {
    config()->set('mediamanager.ai.openrouter.sort', 'price');
    $openRouterSettings = resolve(OpenRouterSettings::class);
    $openRouterSettings->setSort(OpenRouterSort::Latency);

    $openRouterSettings->setSort(null);

    expect($openRouterSettings->sort())->toBe(OpenRouterSort::Price);
});

test('useDefaultSort persists a sentinel that overrides a configured env default', function (): void {
    config()->set('mediamanager.ai.openrouter.sort', 'price');

    resolve(OpenRouterSettings::class)->useDefaultSort();

    expect(resolve(OpenRouterSettings::class)->sort())->toBeNull();
});
