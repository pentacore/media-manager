<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\MediaFileInspectorAgent;
use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\Agents\StuckDownloadInvestigatorAgent;
use App\Ai\Agents\TitleAgent;
use App\Ai\ChatTurnContext;
use App\Ai\Decision\DecisionRunContext;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Enums\OpenRouterSort;
use App\Models\AiTaskModel;
use App\Settings\OpenRouterSettings;
use Laravel\Ai\Enums\Lab;

beforeEach(function (): void {
    config()->set('ai.default', 'openai');
});

test('every agent runs on its task selection and sends its reasoning', function (string $agentClass, AiTask $aiTask): void {
    AiTaskModel::factory()->task($aiTask)->selecting('openai', 'gpt-task-model')->reasoning(AiReasoningLevel::High)->create();

    $agent = new $agentClass;

    expect($agent->modelSelection()->model)->toBe('gpt-task-model')
        ->and($agent->providerOptions(Lab::OpenAI)['reasoning']['effort'])->toBe('high');
})->with([
    [MediaAgent::class, AiTask::Chat],
    [TitleAgent::class, AiTask::Title],
    [MediaFileInspectorAgent::class, AiTask::FileInspector],
    [StuckDownloadInvestigatorAgent::class, AiTask::StuckDownloadInvestigator],
    [PriceFetcherAgent::class, AiTask::PriceUpdater],
    [DecisionAgent::class, AiTask::Decision],
]);

test('provider default sends no reasoning but keeps openrouter routing', function (): void {
    config()->set('ai.default', 'openrouter');
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openrouter', 'openai/gpt-5-nano')->reasoning(AiReasoningLevel::ProviderDefault)->create();
    resolve(OpenRouterSettings::class)->setSort(OpenRouterSort::Price);

    $options = new TitleAgent()->providerOptions(Lab::OpenRouter);

    expect($options)->not->toHaveKey('reasoning')
        ->and($options['provider'])->toBe(['sort' => 'price']);
});

test('the decision agent uses the override for its run event', function (): void {
    AiTaskModel::factory()->task(AiTask::Decision)->selecting('openai', 'gpt-decision')->reasoning(AiReasoningLevel::High)->create();
    AiTaskModel::factory()->event('sonarr:Download')->selecting('openai', 'gpt-cheap')->reasoning(AiReasoningLevel::None)->create();
    app()->instance(DecisionRunContext::class, new DecisionRunContext(
        webhookEventId: null, maxActions: 1, sourceService: 'sonarr', eventType: 'Download',
    ));

    $agent = new DecisionAgent;

    expect($agent->modelSelection()->model)->toBe('gpt-cheap')
        ->and($agent->providerOptions(Lab::OpenAI)['reasoning']['effort'])->toBe('none');
});

test('a sub-agent takes the conversation reasoning on its own model', function (): void {
    AiTaskModel::factory()->task(AiTask::FileInspector)->selecting('openai', 'gpt-inspector')->reasoning(AiReasoningLevel::Low)->create();
    resolve(ChatTurnContext::class)->apply('anthropic', 'claude-opus-5-5', AiReasoningLevel::XHigh);

    $agent = new MediaFileInspectorAgent;

    expect($agent->modelSelection()->model)->toBe('gpt-inspector')
        ->and($agent->providerOptions(Lab::OpenAI)['reasoning']['effort'])->toBe('xhigh');
});

test('a structured agent keeps its output format next to an anthropic effort', function (): void {
    AiTaskModel::factory()->task(AiTask::Title)->selecting('anthropic', 'claude-opus-5')->reasoning(AiReasoningLevel::Low)->create();

    $options = new TitleAgent()->providerOptions(Lab::Anthropic);

    expect($options['output_config']['effort'])->toBe('low')
        ->and($options['output_config']['format']['type'])->toBe('json_schema')
        ->and($options['output_config']['format']['schema'])->toBeArray();
});

test('chat asks openai for a reasoning summary', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->reasoning(AiReasoningLevel::Medium)->create();

    expect(new MediaAgent()->providerOptions(Lab::OpenAI)['reasoning'])->toBe(['effort' => 'medium', 'summary' => 'auto']);
});

test('the failover provider gets the reasoning mapped for its own model', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->reasoning(AiReasoningLevel::None)->create();
    AiTaskModel::factory()->task(AiTask::Failover)->selecting('anthropic', 'claude-sonnet-5-5')->create();

    expect(new MediaAgent()->providerOptions(Lab::Anthropic))->toBe(['thinking' => ['type' => 'between_tools']]);
});
