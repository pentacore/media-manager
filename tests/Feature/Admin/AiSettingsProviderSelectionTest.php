<?php

declare(strict_types=1);

use App\Ai\OpenRouterRequestOptions;
use App\Enums\OpenRouterSort;
use App\Models\AiModelPrice;
use App\Models\User;
use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;
use App\Settings\OpenRouterSettings;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Enums\Lab;

beforeEach(function (): void {
    Cache::flush();
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
});

function providerSelectionPayload(array $overrides = []): array
{
    return [
        'mode' => 'executive',
        'model' => 'gpt-5-mini',
        'title_model' => 'gpt-5.4-nano',
        'advisor_reasoning_level' => 'none',
        ...$overrides,
    ];
}

test('index exposes the providers of every model setting and the OpenRouter preferences', function (): void {
    AiModelPrice::factory()->create(['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5']);
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setModelProvider('openrouter');
    $aiSettings->setModel('anthropic/claude-sonnet-5');

    resolve(OpenRouterSettings::class)->setOrder(['anthropic', 'amazon-bedrock']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiSettings/Index')
            ->where('settings.model_provider', 'openrouter')
            ->where('settings.model', 'anthropic/claude-sonnet-5')
            ->where('settings.title_model_provider', 'openai')
            ->where('settings.sub_agent_model_provider', null)
            ->where('settings.failover_model', null)
            ->where('settings.openrouter.order', 'anthropic, amazon-bedrock')
            ->where('settings.openrouter.allow_fallbacks', true)
            ->has('models.openrouter')
            ->has('openRouterSorts', 3)
            ->where('failoverProviders', fn ($providers): bool => collect($providers)->contains('value', 'openrouter')));
});

test('admin can route chat, titles, sub-agents and the price updater through OpenRouter', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), providerSelectionPayload([
            'model_provider' => 'openrouter',
            'model' => 'anthropic/claude-sonnet-5',
            'title_model_provider' => 'openrouter',
            'title_model' => 'auto',
            'sub_agent_model_provider' => 'openrouter',
            'sub_agent_model' => 'anthropic/claude-haiku-4.5',
            'price_updater_model_provider' => 'openrouter',
            'price_updater_model' => 'x-ai/grok-4',
            'failover_provider' => 'anthropic',
            'failover_model' => 'claude-haiku-4-5',
        ]))
        ->assertRedirect(route('admin.ai-settings.index'));

    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->chatSelection()->toArray())->toBe(['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5'])
        ->and($aiSettings->titleSelection()->toArray())->toBe(['provider' => 'openrouter', 'model' => 'anthropic/claude-haiku-4.5'])
        ->and($aiSettings->subAgentSelection()->toArray())->toBe(['provider' => 'openrouter', 'model' => 'anthropic/claude-haiku-4.5'])
        ->and($aiSettings->priceUpdaterSelection()->toArray())->toBe(['provider' => 'openrouter', 'model' => 'x-ai/grok-4'])
        ->and($aiSettings->failoverModel())->toBe('claude-haiku-4-5');
});

test('OpenRouter can be the failover provider', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), providerSelectionPayload(['failover_provider' => 'openrouter']))
        ->assertRedirect();

    expect(resolve(AiSettings::class)->failoverProvider()?->value)->toBe('openrouter');
});

test('changing the failover provider without submitting a model clears the stale model id', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setFailoverProvider(Lab::Anthropic);
    $aiSettings->setFailoverModel('claude-haiku-4-5');

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), providerSelectionPayload(['failover_provider' => 'openrouter']))
        ->assertRedirect();

    expect($aiSettings->failoverProvider()?->value)->toBe('openrouter')
        ->and($aiSettings->failoverModel())->toBeNull();
});

test('changing the failover provider with a submitted model keeps it', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setFailoverProvider(Lab::Anthropic);
    $aiSettings->setFailoverModel('claude-haiku-4-5');

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), providerSelectionPayload([
            'failover_provider' => 'openrouter',
            'failover_model' => 'anthropic/claude-sonnet-5',
        ]))
        ->assertRedirect();

    expect($aiSettings->failoverProvider()?->value)->toBe('openrouter')
        ->and($aiSettings->failoverModel())->toBe('anthropic/claude-sonnet-5');
});

test('setting the failover provider to none always clears the failover model', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setFailoverProvider(Lab::Anthropic);
    $aiSettings->setFailoverModel('claude-haiku-4-5');

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), providerSelectionPayload(['failover_provider' => 'none']))
        ->assertRedirect();

    expect($aiSettings->failoverProvider())->toBeNull()
        ->and($aiSettings->failoverModel())->toBeNull();
});

test('omitting provider fields leaves the saved providers untouched', function (): void {
    resolve(AiSettings::class)->setModelProvider('openrouter');

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), providerSelectionPayload(['model' => 'openai/gpt-5-mini']))
        ->assertRedirect();

    expect(resolve(AiSettings::class)->modelProvider())->toBe('openrouter');
});

test('blank sub-agent selection fields clear back to the chat selection', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setSubAgentModel('claude-haiku-4-5');
    $aiSettings->setSubAgentModelProvider('anthropic');

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), providerSelectionPayload([
            'sub_agent_model_provider' => '',
            'sub_agent_model' => '',
        ]))
        ->assertRedirect();

    expect($aiSettings->rawSubAgentModel())->toBeNull()
        ->and($aiSettings->rawSubAgentModelProvider())->toBeNull();
});

test('a provider without an API key is rejected', function (string $field): void {
    config()->set('ai.providers.mistral.key');

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), providerSelectionPayload([$field => 'mistral']))
        ->assertSessionHasErrors($field);
})->with(['model_provider', 'title_model_provider', 'sub_agent_model_provider', 'price_updater_model_provider']);

test('a provider that cannot serve text is rejected', function (): void {
    config()->set('ai.providers.cohere.key', 'cohere-test-key');

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), providerSelectionPayload(['model_provider' => 'cohere']))
        ->assertSessionHasErrors('model_provider');
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

test('the decision agent settings save a provider with the model', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.decision-agent.index'))
        ->assertInertia(fn ($page) => $page->where('settings.model_provider', 'openai'));

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.decision-agent.update'), [
            'enabled' => true,
            'model_provider' => 'openrouter',
            'model' => 'openai/gpt-5-mini',
            'event_allowlist' => [],
            'allow_manual_import' => false,
            'notify_on_suggest' => false,
            'notify_on_act' => false,
            'max_actions_per_run' => 3,
            'reasoning_level' => 'none',
        ])
        ->assertRedirect();

    expect(resolve(DecisionAgentSettings::class)->selection()->toArray())->toBe(['provider' => 'openrouter', 'model' => 'openai/gpt-5-mini']);
});
