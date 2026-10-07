<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\MediaFileInspectorAgent;
use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\Agents\StuckDownloadInvestigatorAgent;
use App\Ai\Agents\TitleAgent;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use App\Services\AiUsage\Pricing\InUsePricingModels;
use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    config()->set('mediamanager.ai.sub_agent_model', '');
    config()->set('mediamanager.ai.pricing.updater_model', '');
    config()->set('mediamanager.decision_agent.model', '');
});

function agentProviderSelectChat(string $provider, string $model): void
{
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setModelProvider($provider);
    $aiSettings->setModel($model);
}

test('MediaAgent resolves the chat provider chain', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openrouter', 'anthropic/claude-sonnet-5')->create();
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => Lab::Anthropic->value])->create();
    // AiSettings::providerChainFor() still reads the failover from the old setting.
    resolve(AiSettings::class)->setFailoverProvider(Lab::Anthropic);

    expect((new MediaAgent)->provider())->toBe(['openrouter' => 'anthropic/claude-sonnet-5', 'anthropic' => null]);
});

test('sub-agents resolve the sub-agent selection instead of the default provider', function (string $agentClass): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5-mini')->create();
    AiTaskModel::factory()->task(AiTask::FileInspector)->selecting('openrouter', 'anthropic/claude-haiku-4.5')->create();
    AiTaskModel::factory()->task(AiTask::StuckDownloadInvestigator)->selecting('openrouter', 'anthropic/claude-haiku-4.5')->create();

    expect((new $agentClass)->provider())->toBe(['openrouter' => 'anthropic/claude-haiku-4.5']);
})->with([MediaFileInspectorAgent::class, StuckDownloadInvestigatorAgent::class]);

test('the title, decision and price agents resolve their own selections', function (): void {
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openrouter', 'openai/gpt-5.4-nano')->create();
    AiTaskModel::factory()->task(AiTask::PriceUpdater)->selecting('openrouter', 'x-ai/grok-4')->create();
    AiTaskModel::factory()->task(AiTask::Decision)->selecting('anthropic', 'claude-haiku-4-5')->create();

    expect((new TitleAgent)->provider())->toBe(['openrouter' => 'openai/gpt-5.4-nano'])
        ->and((new DecisionAgent)->provider())->toBe(['anthropic' => 'claude-haiku-4-5'])
        ->and((new PriceFetcherAgent)->provider())->toBe(['openrouter' => 'x-ai/grok-4']);
});

test('a title prompt without a provider argument is sent to the selected OpenRouter model', function (): void {
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openrouter', 'openai/gpt-5.4-nano')->create();

    Http::fake([
        'openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-1',
            'model' => 'openai/gpt-5.4-nano',
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"title":"Weekend Movie Picks"}']]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 6, 'total_tokens' => 18],
        ]),
    ]);

    $agentResponse = (new TitleAgent)->prompt('what should I watch this weekend?');

    expect($agentResponse['title'])->toBe('Weekend Movie Picks');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
        && $request['model'] === 'openai/gpt-5.4-nano');
});

test('in-use pricing models are keyed by each selection provider', function (): void {
    agentProviderSelectChat('openrouter', 'anthropic/claude-sonnet-5');
    resolve(DecisionAgentSettings::class)->setModel('claude-haiku-4-5');
    resolve(DecisionAgentSettings::class)->setModelProvider('anthropic');

    $inUsePricingModels = resolve(InUsePricingModels::class);

    expect($inUsePricingModels->contains('openrouter', 'anthropic/claude-sonnet-5'))->toBeTrue()
        ->and($inUsePricingModels->contains('anthropic', 'claude-haiku-4-5'))->toBeTrue()
        ->and($inUsePricingModels->contains('openai', 'anthropic/claude-sonnet-5'))->toBeFalse();
});
