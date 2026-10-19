<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\TitleAgent;
use App\Ai\OpenRouterRequestOptions;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Enums\OpenRouterSort;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Settings\OpenRouterSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
});

test('non-OpenRouter providers get no OpenRouter options', function (): void {
    resolve(OpenRouterSettings::class)->setSort(OpenRouterSort::Price);

    expect(resolve(OpenRouterRequestOptions::class)->for(Lab::OpenAI))->toBe([]);
});

test('routing is empty at OpenRouter defaults', function (): void {
    expect(resolve(OpenRouterRequestOptions::class)->routing())->toBe([])
        ->and(resolve(OpenRouterRequestOptions::class)->for(Lab::OpenRouter))->toBe([]);
});

test('routing sends only the preferences that differ from OpenRouter defaults', function (): void {
    $openRouterSettings = resolve(OpenRouterSettings::class);
    $openRouterSettings->setSort(OpenRouterSort::Throughput);
    $openRouterSettings->setDenyDataCollection(true);
    $openRouterSettings->setAllowFallbacks(false);
    $openRouterSettings->setOrder(['anthropic', 'amazon-bedrock']);
    $openRouterSettings->setIgnore(['deepinfra']);

    expect(resolve(OpenRouterRequestOptions::class)->for('openrouter'))->toBe([
        'provider' => [
            'sort' => 'throughput',
            'data_collection' => 'deny',
            'allow_fallbacks' => false,
            'order' => ['anthropic', 'amazon-bedrock'],
            'ignore' => ['deepinfra'],
        ],
    ]);
});

test('upstream slug lists are trimmed, lowercased, de-duplicated and emptied to omission', function (): void {
    $openRouterSettings = resolve(OpenRouterSettings::class);
    $openRouterSettings->setOrder([' Anthropic ', '', 'amazon-bedrock ', 'anthropic']);
    $openRouterSettings->setIgnore(['  ']);

    expect($openRouterSettings->order())->toBe(['anthropic', 'amazon-bedrock'])
        ->and($openRouterSettings->ignore())->toBe([])
        ->and(resolve(OpenRouterRequestOptions::class)->routing())->toBe(['provider' => ['order' => ['anthropic', 'amazon-bedrock']]]);
});

test('MediaAgent sends reasoning and routing to OpenRouter', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openrouter', 'openai/gpt-5')->reasoning(AiReasoningLevel::High)->create();
    resolve(OpenRouterSettings::class)->setDenyDataCollection(true);

    expect((new MediaAgent)->providerOptions(Lab::OpenRouter))->toBe([
        'reasoning' => ['effort' => 'high'],
        'provider' => ['data_collection' => 'deny'],
    ])->and((new MediaAgent)->providerOptions(Lab::OpenAI))->toBe([
        'reasoning' => ['effort' => 'high', 'summary' => 'auto'],
    ]);
});

test('a custom-named OpenRouter provider gets the routing preferences', function (): void {
    config()->set('ai.providers.my-or', ['driver' => 'openrouter', 'key' => 'sk-or-test']);
    resolve(OpenRouterSettings::class)->setSort(OpenRouterSort::Price);

    expect(resolve(OpenRouterRequestOptions::class)->for('my-or'))->toBe(['provider' => ['sort' => 'price']]);
});

test('MediaAgent sends clamped reasoning and routing to a custom-named OpenRouter provider', function (string|Lab $provider): void {
    config()->set('ai.providers.my-or', ['driver' => 'openrouter', 'key' => 'sk-or-test']);
    AiModelPrice::factory()->create(['provider' => 'my-or', 'model' => 'openai/gpt-5', 'supports_reasoning' => true, 'reasoning_levels' => ['low', 'medium']]);
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('my-or', 'openai/gpt-5')->reasoning(AiReasoningLevel::High)->create();
    resolve(OpenRouterSettings::class)->setDenyDataCollection(true);

    expect((new MediaAgent)->providerOptions($provider))->toBe([
        'reasoning' => ['effort' => 'medium'],
        'provider' => ['data_collection' => 'deny'],
    ]);
})->with([
    'the driver the SDK passes' => [Lab::OpenRouter],
    'the configured name' => ['my-or'],
]);

test('an OpenRouter request body carries the routing preferences', function (): void {
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openrouter', 'openai/gpt-5.4-nano')->create();

    resolve(OpenRouterSettings::class)->setSort(OpenRouterSort::Price);

    Http::fake([
        'openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-1',
            'model' => 'openai/gpt-5.4-nano',
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"title":"Hi"}']]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
        ]),
    ]);

    (new TitleAgent)->prompt('hi');

    Http::assertSent(fn (Request $request): bool => $request['provider'] === ['sort' => 'price']);
});
