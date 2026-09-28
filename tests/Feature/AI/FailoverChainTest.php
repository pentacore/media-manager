<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\MediaFileInspectorAgent;
use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\Agents\StuckDownloadInvestigatorAgent;
use App\Ai\Agents\TitleAgent;
use App\Enums\AgentDecisionStatus;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Jobs\RunDecisionAgent;
use App\Models\AgentDecision;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult as StreamedToolResult;

beforeEach(function (): void {
    Cache::flush();
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
});

/**
 * Fake the stuck-download investigator so the primary provider is
 * overloaded and only the failover provider answers with findings.
 *
 * @param  list<string>  $providersTried
 */
function failoverChainInvestigatorFake(array &$providersTried): void
{
    StuckDownloadInvestigatorAgent::fake(function (string $prompt, Collection $attachments, Provider $provider) use (&$providersTried): array {
        $providersTried[] = $provider->name();

        if ($provider->name() === 'openai') {
            throw ProviderOverloadedException::forProvider('openai');
        }

        return [
            'service' => 'sonarr', 'download_id' => 'abc', 'title' => 'Show S01E01',
            'files' => ['/dl/show.mkv | mapped | not an upgrade'], 'recommendation' => 'remove',
            'blocklist' => false, 'search_replacement' => false, 'reason' => 'Existing file is better.',
        ];
    });
}

test('every agent offers the failover chain for its own model', function (string $agentClass): void {
    resolve(AiSettings::class)->setFailoverProvider(Lab::Anthropic);
    $agent = new $agentClass;

    expect($agent->provider())->toBe([
        Lab::OpenAI->value => $agent->model(),
        Lab::Anthropic->value => null,
    ]);
})->with([
    MediaAgent::class,
    DecisionAgent::class,
    TitleAgent::class,
    PriceFetcherAgent::class,
    StuckDownloadInvestigatorAgent::class,
    MediaFileInspectorAgent::class,
]);

test('without a failover provider every agent leaves the provider to the SDK default', function (string $agentClass): void {
    expect((new $agentClass)->provider())->toBeNull();
})->with([
    MediaAgent::class,
    DecisionAgent::class,
    TitleAgent::class,
    PriceFetcherAgent::class,
    StuckDownloadInvestigatorAgent::class,
    MediaFileInspectorAgent::class,
]);

test('a delegated sub-agent fails over when the primary provider errors', function (): void {
    resolve(AiSettings::class)->setFailoverProvider(Lab::Anthropic);
    $providersTried = [];
    failoverChainInvestigatorFake($providersTried);
    MediaAgent::fake([
        new ToolCall(id: 'c1', name: 'InvestigateStuckDownload', arguments: ['task' => 'Why is download abc stuck?']),
        'It is not an upgrade; I can remove it.',
    ]);

    $agentResponse = (new MediaAgent)->prompt('why is my download stuck?');

    expect($providersTried)->toBe(['openai', 'anthropic'])
        ->and((string) $agentResponse->toolResults->first()->result)
        ->toContain('"recommendation":"remove"')
        ->not->toContain('Agent failed');
});

test('a sub-agent delegated from a streamed turn fails over too', function (): void {
    resolve(AiSettings::class)->setFailoverProvider(Lab::Anthropic);
    $providersTried = [];
    failoverChainInvestigatorFake($providersTried);
    MediaAgent::fake([
        new ToolCall(id: 'c1', name: 'InvestigateStuckDownload', arguments: ['task' => 'Why is download abc stuck?']),
        'It is not an upgrade; I can remove it.',
    ]);

    $events = iterator_to_array((new MediaAgent)->stream('why is my download stuck?'), false);
    $toolResult = collect($events)->first(fn (object $event): bool => $event instanceof StreamedToolResult && ! $event->preliminary);

    expect($providersTried)->toBe(['openai', 'anthropic'])
        ->and((string) $toolResult->toolResult->result)
        ->toContain('"recommendation":"remove"')
        ->not->toContain('Agent failed');
});

test('a chat turn fails over without the controller passing a provider', function (): void {
    Bus::fake([GenerateConversationTitle::class]);
    resolve(AiSettings::class)->setFailoverProvider(Lab::Anthropic);
    MediaAgent::fake(fn (string $prompt, Collection $attachments, Provider $provider): string => $provider->name() === 'openai'
        ? throw ProviderOverloadedException::forProvider('openai')
        : 'Served by the failover provider.');

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('ai.chat.send'), ['message' => 'hi'])
        ->assertOk()
        ->assertJsonPath('text', 'Served by the failover provider.');
});

test('a decision run fails over without the job passing a provider', function (): void {
    resolve(DecisionAgentSettings::class)->setEnabled(true);
    resolve(AiSettings::class)->setFailoverProvider(Lab::Anthropic);
    DecisionAgent::fake(fn (string $prompt, Collection $attachments, Provider $provider): string => $provider->name() === 'openai'
        ? throw ProviderOverloadedException::forProvider('openai')
        : 'Nothing to do; answered by the failover provider.');
    $webhookEvent = WebhookEvent::factory()->create();

    app()->call([new RunDecisionAgent($webhookEvent->id, 'sonarr', 'ManualInteractionRequired', ['eventType' => 'ManualInteractionRequired']), 'handle']);

    expect(AgentDecision::query()->where('webhook_event_id', $webhookEvent->id)->sole())
        ->status->toBe(AgentDecisionStatus::NoAction)
        ->summary->toBe('Nothing to do; answered by the failover provider.');
});
