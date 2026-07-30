<?php

declare(strict_types=1);

use App\Ai\Agents\PriceFetcherAgent;
use App\Models\AiModelPrice;
use App\Models\AiPriceRefreshRun;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Services\AiUsage\Pricing\AiPriceRefreshCoordinator;
use App\Services\AiUsage\Pricing\Data\RefreshReport;
use App\Services\AiUsage\Pricing\RefreshScope;
use App\Settings\AiSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;

beforeEach(function (): void {
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);
    config()->set('mediamanager.ai.pricing.models_dev.retries', 0);
});

/**
 * An allowlisted pricing-page URL per canonical provider, used as the
 * `source_url` for scripted verifier writes so they pass the upsert tool's
 * provenance gate.
 */
function verifierSourceUrl(string $provider): string
{
    return match ($provider) {
        'anthropic' => 'https://claude.com/pricing',
        'gemini' => 'https://ai.google.dev/gemini-api/docs/pricing',
        'xai' => 'https://x.ai/api',
        'deepseek' => 'https://api-docs.deepseek.com/quick_start/pricing',
        'mistral' => 'https://mistral.ai/pricing/',
        'groq' => 'https://groq.com/pricing',
        'cohere' => 'https://cohere.com/pricing',
        'openrouter' => 'https://openrouter.ai/api/v1/models',
        default => 'https://developers.openai.com/api/docs/pricing',
    };
}

/**
 * Fake every first-party pricing-page host with a stub 200 page so scripted
 * WebFetchTool calls fetch (and record receipts) without leaving the test.
 * Deliberately excludes models.dev, whose stub each scenario controls itself.
 */
function fakePricingPages(): void
{
    Http::fake([
        'developers.openai.com/*' => Http::response('openai pricing page', 200),
        'claude.com/*' => Http::response('anthropic pricing page', 200),
        'ai.google.dev/*' => Http::response('gemini pricing page', 200),
        'x.ai/*' => Http::response('xai pricing page', 200),
        'api-docs.deepseek.com/*' => Http::response('deepseek pricing page', 200),
        'mistral.ai/*' => Http::response('mistral pricing page', 200),
        'groq.com/*' => Http::response('groq pricing page', 200),
        'cohere.com/*' => Http::response('cohere pricing page', 200),
        'openrouter.ai/*' => Http::response('openrouter models page', 200),
    ]);
}

/**
 * Fake the verifier agent so it performs real UpsertModelPriceTool calls for
 * the given canonical provider => model-ids map, recording genuine
 * VERIFICATION-GRADE write outcomes into the run ledger. It runs on the default
 * receipt-gated custom-fetch path: each provider's canonical page is fetched
 * first (recording a receipt) so the subsequent upserts are receipt-backed and
 * genuinely resolve their targets. Provider-native (receiptless) writes no
 * longer resolve anything, so this is the only way a faked verifier "resolves"
 * a fallback under the ledger-driven policy — prompt completion is not enough.
 *
 * @param  array<string, list<string>>  $writes
 */
function fakeVerifierWrites(array $writes, string $final = 'verified'): void
{
    fakePricingPages();

    $responses = [];
    $fetched = [];

    foreach ($writes as $provider => $models) {
        $url = verifierSourceUrl($provider);

        if (! in_array($url, $fetched, true)) {
            $responses[] = new ToolCall((string) Str::ulid(), 'WebFetchTool', ['url' => $url]);
            $fetched[] = $url;
        }

        foreach ($models as $model) {
            $responses[] = new ToolCall((string) Str::ulid(), 'UpsertModelPriceTool', [
                'provider' => $provider,
                'model' => $model,
                'input_per_mtok' => 1.0,
                'output_per_mtok' => 2.0,
                'cache_read_per_mtok' => null,
                'cache_write_per_mtok' => null,
                'reasoning_per_mtok' => null,
                'batch_input_per_mtok' => null,
                'batch_output_per_mtok' => null,
                'batch_cache_read_per_mtok' => null,
                'batch_cache_write_per_mtok' => null,
                'batch_reasoning_per_mtok' => null,
                'source_url' => $url,
                'source_updated_at' => null,
            ]);
        }
    }

    $responses[] = $final;

    PriceFetcherAgent::fake($responses);
}

/**
 * Build a minimal valid Models.dev model entry.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function feedModel(float $input = 1.0, float $output = 2.0, array $extra = []): array
{
    return array_merge(
        [
            'cost' => array_merge(['input' => $input, 'output' => $output], $extra['cost'] ?? []),
            'modalities' => ['output' => ['text']],
        ],
        array_diff_key($extra, ['cost' => true]),
    );
}

/**
 * Fake the Models.dev endpoint with the given provider => models payload.
 *
 * @param  array<string, mixed>  $providers
 */
function fakeFeed(array $providers): void
{
    Http::fake([
        'models.dev/*' => Http::response(json_encode($providers, JSON_THROW_ON_ERROR), 200),
    ]);
}

/**
 * Run the coordinator with sensible defaults for the scenario tests.
 */
function runCoordinator(
    string $mode = AiPriceRefreshCoordinator::MODE_APPLY,
    string $source = AiPriceRefreshCoordinator::SOURCE_HYBRID,
    ?RefreshScope $scope = null,
    ?User $triggeredBy = null,
    string $trigger = 'test',
    bool $dryRun = false,
): RefreshReport {
    return resolve(AiPriceRefreshCoordinator::class)->run(
        mode: $mode,
        source: $source,
        scope: $scope ?? RefreshScope::all(),
        triggeredBy: $triggeredBy,
        trigger: $trigger,
        dryRun: $dryRun,
    );
}

test('full feed success writes the catalog without invoking the agent', function (): void {
    PriceFetcherAgent::fake(['ok']);

    fakeFeed([
        'openai' => ['models' => ['gpt-feed' => feedModel(1.25, 10.0)]],
        'anthropic' => ['models' => ['claude-feed' => feedModel(3.0, 15.0)]],
        'google' => ['models' => ['gemini-feed' => feedModel(1.25, 5.0)]],
    ]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic', 'gemini']));

    PriceFetcherAgent::assertNeverPrompted();

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and($refreshReport->modelsDevStatus)->toBe('ok')
        ->and($refreshReport->providersRequested)->toBe(3)
        ->and($refreshReport->providersSucceeded)->toBe(3)
        ->and($refreshReport->providersFailed)->toBe(0)
        ->and($refreshReport->modelsCreated)->toBe(3)
        ->and($refreshReport->fallbackProviders)->toBe([])
        ->and($refreshReport->runId)->not->toBeNull();

    expect(AiModelPrice::query()->where('provider', 'gemini')->where('model', 'gemini-feed')->exists())->toBeTrue()
        ->and(AiModelPrice::query()->count())->toBe(3);

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->status)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and($aiPriceRefreshRun->completed_at)->not->toBeNull();
});

test('global transport failure falls back to the six core providers only', function (): void {
    Sleep::fake();
    fakeVerifierWrites([
        'openai' => ['gpt-verify'],
        'anthropic' => ['claude-verify'],
        'gemini' => ['gemini-verify'],
        'xai' => ['grok-verify'],
        'deepseek' => ['deepseek-verify'],
        'mistral' => ['mistral-verify'],
    ]);

    Http::fake(['models.dev/*' => Http::response('upstream down', 500)]);

    $refreshReport = runCoordinator();

    expect($refreshReport->modelsDevStatus)->toBe('server_error')
        ->and($refreshReport->fallbackProviders)->toBe(['openai', 'anthropic', 'gemini', 'xai', 'deepseek', 'mistral'])
        ->and($refreshReport->providersRequested)->toBe(9)
        ->and($refreshReport->providersSucceeded)->toBe(6)
        ->and($refreshReport->providersFailed)->toBe(3)
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_PARTIAL);

    // The static prompt text names every provider identity, so target scoping
    // is asserted through the canonical source URLs the run lists.
    PriceFetcherAgent::assertPrompted(function (AgentPrompt $agentPrompt): bool {
        $instructions = (string) $agentPrompt->agent->instructions();

        return str_contains($instructions, 'https://api-docs.deepseek.com/quick_start/pricing')
            && str_contains($instructions, 'https://mistral.ai/pricing/')
            && ! str_contains($instructions, 'https://groq.com/pricing')
            && ! str_contains($instructions, 'https://cohere.com/pricing')
            && ! str_contains($instructions, 'https://openrouter.ai/api/v1/models');
    });

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->fallback_targets)->toBe(['openai', 'anthropic', 'gemini', 'xai', 'deepseek', 'mistral'])
        ->and($aiPriceRefreshRun->models_dev_status)->toBe('server_error');
});

test('invalid json feed falls back like a transport failure', function (): void {
    Sleep::fake();
    fakeVerifierWrites(['openai' => ['gpt-verify']]);

    Http::fake(['models.dev/*' => Http::response('{not json', 200)]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai']));

    PriceFetcherAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => true);

    expect($refreshReport->modelsDevStatus)->toBe('invalid_json')
        ->and($refreshReport->fallbackProviders)->toBe(['openai'])
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED);
});

test('an explicitly scoped non-core provider is included in global fallback', function (): void {
    Sleep::fake();
    fakeVerifierWrites([
        'openai' => ['gpt-verify'],
        'groq' => ['groq-verify'],
    ]);

    Http::fake(['models.dev/*' => Http::response('upstream down', 500)]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'groq']));

    expect($refreshReport->fallbackProviders)->toBe(['openai', 'groq'])
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED);
});

test('a requested provider missing from the feed gets targeted fallback', function (): void {
    fakeVerifierWrites(['anthropic' => ['claude-verify']]);

    fakeFeed(['openai' => ['models' => ['gpt-feed' => feedModel()]]]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    PriceFetcherAgent::assertPrompted(function (AgentPrompt $agentPrompt): bool {
        $instructions = (string) $agentPrompt->agent->instructions();

        return str_contains($instructions, 'anthropic')
            && ! str_contains($instructions, 'https://developers.openai.com/api/docs/pricing');
    });

    expect($refreshReport->modelsDevStatus)->toBe('ok')
        ->and($refreshReport->fallbackProviders)->toBe(['anthropic'])
        ->and($refreshReport->providersSucceeded)->toBe(2)
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED);

    expect(AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-feed')->exists())->toBeTrue();
});

test('the audit records real per-provider agent write tallies, not just a row delta', function (): void {
    // The verifier creates two Anthropic rows; the audit must reflect the real
    // per-provider created count from the tool ledger.
    fakeVerifierWrites(['anthropic' => ['claude-a', 'claude-b']]);

    fakeFeed(['openai' => ['models' => ['gpt-feed' => feedModel()]]]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and($refreshReport->providersSucceeded)->toBe(2)
        ->and($refreshReport->modelsCreated)->toBe(3); // 1 openai feed + 2 anthropic agent

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->provider_results['anthropic']['status'])->toBe('fallback')
        ->and($aiPriceRefreshRun->provider_results['anthropic']['created'])->toBe(2)
        ->and($aiPriceRefreshRun->provider_results['openai']['status'])->toBe('ok')
        ->and($aiPriceRefreshRun->provider_results['openai']['created'])->toBe(1);
});

test('a malformed provider among valid providers falls back only for that provider', function (): void {
    fakeVerifierWrites(['anthropic' => ['claude-verify']]);

    fakeFeed([
        'openai' => ['models' => ['gpt-feed' => feedModel()]],
        'anthropic' => ['models' => 'not-a-map'],
    ]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    expect($refreshReport->fallbackProviders)->toBe(['anthropic'])
        ->and($refreshReport->providersSucceeded)->toBe(2)
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and(AiModelPrice::query()->where('provider', 'openai')->exists())->toBeTrue();
});

test('a provider-level fallback with no verified agent write stays unresolved', function (): void {
    // The verifier is prompted for the missing provider but produces no write
    // (a skipped fetch, an unreadable page). Prompt completion alone must not
    // resolve it: the provider stays unresolved and the run degrades to partial.
    PriceFetcherAgent::fake(['I could not read the anthropic pricing page, skipping.']);

    fakeFeed(['openai' => ['models' => ['gpt-feed' => feedModel()]]]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    PriceFetcherAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => true);

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_PARTIAL)
        ->and($refreshReport->providersSucceeded)->toBe(1)
        ->and($refreshReport->providersFailed)->toBe(1)
        ->and($refreshReport->fallbackProviders)->toBe(['anthropic']);

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->provider_results['anthropic']['status'])->toBe('fallback_failed')
        ->and($aiPriceRefreshRun->provider_results['openai']['status'])->toBe('ok');
});

test('a provider-native scripted write no longer resolves a provider-level fallback', function (): void {
    // FIX 1: the provider-native WebFetch path records no receipts, so its
    // writes are never verification-grade. The write itself still lands as a
    // best-effort refresh, but the fallback target must finalize unresolved:
    // the run reads partial and audits the unverified pair.
    config()->set('mediamanager.ai.price_fetcher_provider_webfetch', true);

    PriceFetcherAgent::fake([
        new ToolCall((string) Str::ulid(), 'UpsertModelPriceTool', [
            'provider' => 'anthropic', 'model' => 'claude-native',
            'input_per_mtok' => 3.0, 'output_per_mtok' => 15.0,
            'source_url' => verifierSourceUrl('anthropic'), 'source_updated_at' => null,
        ]),
        'done',
    ]);

    fakeFeed(['openai' => ['models' => ['gpt-feed' => feedModel()]]]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    PriceFetcherAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => true);

    // The best-effort write landed (unstamped), but it resolves nothing.
    $aiModelPrice = AiModelPrice::query()->where('provider', 'anthropic')->where('model', 'claude-native')->firstOrFail();
    expect($aiModelPrice->pricing_verified_at)->toBeNull();

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_PARTIAL)
        ->and($refreshReport->providersSucceeded)->toBe(1)
        ->and($refreshReport->providersFailed)->toBe(1);

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->provider_results['anthropic']['status'])->toBe('fallback_failed')
        // The write is still tallied for audit even though it resolved nothing.
        ->and($aiPriceRefreshRun->provider_results['anthropic']['created'])->toBe(1)
        ->and($aiPriceRefreshRun->unverified_targets)->toBe(['anthropic:claude-native']);
});

test('a wildcard verification target resolves only when every stored row is covered', function (): void {
    // FIX 4: openai has two stored rows; the verifier confirms only one, so the
    // wildcard target is not fully covered — the run degrades to partial and
    // the uncovered pair is audited as provider:model.
    fakeVerifierWrites(['openai' => ['gpt-covered']]);

    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-covered', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);
    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-uncovered', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);

    // The provider is missing from the feed, so it becomes a wildcard fallback.
    fakeFeed(['anthropic' => ['models' => ['claude-feed' => feedModel()]]]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_PARTIAL)
        ->and($refreshReport->providersSucceeded)->toBe(1)
        ->and($refreshReport->providersFailed)->toBe(1)
        ->and($refreshReport->errorMessage)->toContain('openai:gpt-uncovered');

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->provider_results['openai']['status'])->toBe('fallback_failed')
        ->and($aiPriceRefreshRun->unverified_targets)->toBe(['openai:gpt-uncovered']);
});

test('a wildcard verification target with every stored row covered resolves', function (): void {
    fakeVerifierWrites(['openai' => ['gpt-a', 'gpt-b']]);

    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-a', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);
    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-b', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);

    fakeFeed(['anthropic' => ['models' => ['claude-feed' => feedModel()]]]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and($refreshReport->providersSucceeded)->toBe(2)
        ->and($refreshReport->errorMessage)->toBeNull();

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->provider_results['openai']['status'])->toBe('fallback')
        ->and($aiPriceRefreshRun->unverified_targets)->toBeNull();
});

test('a wildcard provider with zero stored rows resolves on a single verification-grade create', function (): void {
    // No pre-existing openai rows: full coverage is trivially achievable, so a
    // single receipt-backed Created resolves the wildcard.
    fakeVerifierWrites(['openai' => ['gpt-fresh']]);

    fakeFeed(['anthropic' => ['models' => ['claude-feed' => feedModel()]]]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and($refreshReport->providersSucceeded)->toBe(2);

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->provider_results['openai']['status'])->toBe('fallback')
        ->and($aiPriceRefreshRun->unverified_targets)->toBeNull();
});

test('a wildcard provider renders its stored models as a prompt checklist without narrowing the scope', function (): void {
    // FIX 4: to make provider-wide coverage achievable, the agent prompt lists
    // each wildcard provider's stored models as a checklist while the write
    // scope stays wildcard (new models remain writable).
    fakeVerifierWrites(['openai' => ['gpt-old-a', 'gpt-old-b', 'gpt-brand-new']]);

    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-old-a', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);
    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-old-b', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);

    fakeFeed(['anthropic' => ['models' => ['claude-feed' => feedModel()]]]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    PriceFetcherAgent::assertPrompted(function (AgentPrompt $agentPrompt): bool {
        $instructions = (string) $agentPrompt->agent->instructions();

        return str_contains($instructions, 're-confirm each currently-stored model: gpt-old-a, gpt-old-b')
            && str_contains($instructions, 'every generally-available text/chat model');
    });

    // The wildcard scope admitted a model that is not on the checklist.
    expect(AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-brand-new')->exists())->toBeTrue()
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED);
});

test('the unverified targets audit is capped at 25 entries with an overflow marker', function (): void {
    // 30 stored rows, none covered by the failing verifier: the audit lists the
    // first 25 pairs plus a '+5 more' marker instead of the full flood.
    fakePricingPages();
    PriceFetcherAgent::fake(['could not read anything']);

    for ($i = 1; $i <= 30; $i++) {
        AiModelPrice::create([
            'provider' => 'openai',
            'model' => sprintf('gpt-bulk-%02d', $i),
            'input_per_mtok' => 1.0,
            'output_per_mtok' => 2.0,
        ]);
    }

    fakeFeed(['anthropic' => ['models' => ['claude-feed' => feedModel()]]]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_PARTIAL);

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->unverified_targets)->toHaveCount(26)
        ->and($aiPriceRefreshRun->unverified_targets[0])->toBe('openai:gpt-bulk-01')
        ->and($aiPriceRefreshRun->unverified_targets[24])->toBe('openai:gpt-bulk-25')
        ->and($aiPriceRefreshRun->unverified_targets[25])->toBe('+5 more');
});

test('a malformed model among valid models is rejected without triggering fallback', function (): void {
    PriceFetcherAgent::fake(['ok']);

    fakeFeed([
        'openai' => ['models' => [
            'gpt-good' => feedModel(),
            'gpt-bad' => 'junk',
        ]],
    ]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai']));

    PriceFetcherAgent::assertNeverPrompted();

    expect($refreshReport->modelsCreated)->toBe(1)
        ->and($refreshReport->modelsRejected)->toBe(1)
        ->and($refreshReport->fallbackProviders)->toBe([])
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED);
});

test('deprecated and non-text models never trigger fallback', function (): void {
    PriceFetcherAgent::fake(['ok']);

    fakeFeed([
        'openai' => ['models' => [
            'gpt-good' => feedModel(),
            'gpt-old' => feedModel(extra: ['deprecated' => true]),
            'embedding-3' => feedModel(extra: ['modalities' => ['output' => ['embedding']]]),
        ]],
    ]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai']));

    PriceFetcherAgent::assertNeverPrompted();

    expect($refreshReport->modelsCreated)->toBe(1)
        ->and($refreshReport->modelsRejected)->toBe(2)
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and(AiModelPrice::query()->count())->toBe(1);
});

test('the models-dev source never falls back to the agent', function (): void {
    Sleep::fake();
    PriceFetcherAgent::fake(['ok']);

    Http::fake(['models.dev/*' => Http::response('upstream down', 500)]);

    $refreshReport = runCoordinator(
        source: AiPriceRefreshCoordinator::SOURCE_MODELS_DEV,
        scope: RefreshScope::forProviders(['openai']),
    );

    PriceFetcherAgent::assertNeverPrompted();

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_FAILED)
        ->and($refreshReport->modelsDevStatus)->toBe('server_error')
        ->and($refreshReport->fallbackProviders)->toBe([])
        ->and($refreshReport->errorMessage)->not->toBeNull();

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->status)->toBe(RefreshReport::RESULT_FAILED)
        ->and($aiPriceRefreshRun->error_message)->not->toBeNull();
});

test('the agent source skips the feed entirely', function (): void {
    fakeVerifierWrites(['openai' => ['gpt-verify']]);
    Http::fake();

    $refreshReport = runCoordinator(
        source: AiPriceRefreshCoordinator::SOURCE_AGENT,
        scope: RefreshScope::forProviders(['openai']),
    );

    // The verifier legitimately fetches first-party pricing pages; only the
    // Models.dev feed must be untouched on these feed-less paths.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'models.dev'));
    PriceFetcherAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => true);

    expect($refreshReport->modelsDevStatus)->toBe('skipped')
        ->and($refreshReport->fallbackProviders)->toBe(['openai'])
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED);
});

test('hybrid behaves agent-only when the feed is disabled', function (): void {
    config()->set('mediamanager.ai.pricing.models_dev.enabled', false);

    fakeVerifierWrites([
        'openai' => ['gpt-verify'],
        'anthropic' => ['claude-verify'],
    ]);
    Http::fake();

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    // The verifier legitimately fetches first-party pricing pages; only the
    // Models.dev feed must be untouched on these feed-less paths.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'models.dev'));
    PriceFetcherAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => true);

    expect($refreshReport->modelsDevStatus)->toBe('disabled')
        ->and($refreshReport->fallbackProviders)->toBe(['openai', 'anthropic'])
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED);
});

test('a saved feed-disabled setting overrides the enabled env default', function (): void {
    // Config/env default is ON (see beforeEach); the persisted admin setting
    // must win and force the agent-only path.
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);
    resolve(AiSettings::class)->setModelsDevPricingEnabled(false);

    fakeVerifierWrites([
        'openai' => ['gpt-verify'],
        'anthropic' => ['claude-verify'],
    ]);
    Http::fake();

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    // The verifier legitimately fetches first-party pricing pages; only the
    // Models.dev feed must be untouched on these feed-less paths.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'models.dev'));
    PriceFetcherAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => true);

    expect($refreshReport->modelsDevStatus)->toBe('disabled')
        ->and($refreshReport->fallbackProviders)->toBe(['openai', 'anthropic'])
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED);
});

test('a model-scoped fallback binds the exact provider models to the agent', function (): void {
    PriceFetcherAgent::fake(['ok']);

    fakeFeed(['openai' => ['models' => ['gpt-feed' => feedModel()]]]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviderModels(['anthropic' => ['claude-target']]));

    PriceFetcherAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), 'claude-target'));

    expect($refreshReport->fallbackProviders)->toBe(['anthropic']);

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->fallback_targets)->toBe(['anthropic:claude-target']);
});

test('an unaddressed anomaly target degrades the run to partial with an unverified_targets audit', function (): void {
    PriceFetcherAgent::fake(['verified: value looks wrong upstream, left unchanged']);

    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-anom', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);

    // 10x input jump breaches the 4x anomaly ceiling.
    fakeFeed(['openai' => ['models' => ['gpt-anom' => feedModel(10.0, 2.0)]]]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai']));

    // The candidate is withheld and the agent verifies the exact pair. The
    // per-provider focus line names the anomalous model only.
    PriceFetcherAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), 'only these models: gpt-anom'));

    // The faked agent wrote nothing, so the stored value is preserved.
    expect((string) AiModelPrice::query()->where('model', 'gpt-anom')->firstOrFail()->input_per_mtok)->toBe('1.0000');

    // The exact-model target was never resolved by a real write: the provider
    // keeps its feed-resolved ok status, but the run degrades to partial and
    // audits the unverified pair.
    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_PARTIAL)
        ->and($refreshReport->providersSucceeded)->toBe(1)
        ->and($refreshReport->modelsUpdated)->toBe(0)
        ->and($refreshReport->modelsRejected)->toBe(0)
        ->and($refreshReport->fallbackProviders)->toBe(['openai'])
        ->and($refreshReport->errorMessage)->toContain('openai:gpt-anom');

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->fallback_targets)->toBe(['openai:gpt-anom'])
        ->and($aiPriceRefreshRun->unverified_targets)->toBe(['openai:gpt-anom'])
        ->and($aiPriceRefreshRun->provider_results['openai']['anomalous'])->toBe(1)
        ->and($aiPriceRefreshRun->provider_results['openai']['status'])->toBe('ok');
});

test('an anomaly target verified by an exact model write resolves and the run succeeds', function (): void {
    // The verifier re-reads the page and confirms the stored value (an
    // Unchanged outcome for the exact provider:model), which resolves the
    // anomaly target; the withheld feed value stays discarded.
    fakeVerifierWrites(['openai' => ['gpt-anom']]);

    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-anom', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);

    fakeFeed(['openai' => ['models' => ['gpt-anom' => feedModel(10.0, 2.0)]]]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai']));

    expect((string) AiModelPrice::query()->where('model', 'gpt-anom')->firstOrFail()->input_per_mtok)->toBe('1.0000');

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and($refreshReport->providersSucceeded)->toBe(1)
        ->and($refreshReport->errorMessage)->toBeNull();

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->unverified_targets)->toBeNull()
        ->and($aiPriceRefreshRun->provider_results['openai']['status'])->toBe('ok');
});

test('an anomaly write that is rejected again by the verifier leaves the target unverified', function (): void {
    // The provider-native verifier re-sends the same implausible value; the
    // guard rejects it (no bypass without a receipt), so the exact-model target
    // stays unverified and the run degrades to partial.
    config()->set('mediamanager.ai.price_fetcher_provider_webfetch', true);
    PriceFetcherAgent::fake([
        new ToolCall((string) Str::ulid(), 'UpsertModelPriceTool', [
            'provider' => 'openai', 'model' => 'gpt-anom',
            'input_per_mtok' => 10.0, 'output_per_mtok' => 2.0,
            'source_url' => verifierSourceUrl('openai'), 'source_updated_at' => null,
        ]),
        'done',
    ]);

    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-anom', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);

    fakeFeed(['openai' => ['models' => ['gpt-anom' => feedModel(10.0, 2.0)]]]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai']));

    expect((string) AiModelPrice::query()->where('model', 'gpt-anom')->firstOrFail()->input_per_mtok)->toBe('1.0000')
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_PARTIAL);

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->unverified_targets)->toBe(['openai:gpt-anom']);
});

test('a mixed fallback keeps exact-model scope on one provider while opening another wide', function (): void {
    // Anthropic is missing from the feed (whole-provider fallback); OpenAI has
    // one anomalous model (exact-model fallback). The verifier must be able to
    // write any Anthropic model but ONLY the anomalous OpenAI model — a whole-
    // provider fallback on Anthropic must not widen OpenAI to every model.
    fakePricingPages();

    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-anom', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);

    // 10x jump trips the anomaly guard, withholding gpt-anom for verification.
    fakeFeed(['openai' => ['models' => ['gpt-anom' => feedModel(10.0, 2.0)]]]);

    // Receipt-backed custom-fetch path: the verifier fetches each provider page
    // first, then tries an out-of-scope OpenAI model, the in-scope anomalous one
    // (corrected to a plausible value), and a fresh Anthropic model
    // (wildcard-allowed). Only receipt-backed writes are verification-grade.
    PriceFetcherAgent::fake([
        new ToolCall((string) Str::ulid(), 'WebFetchTool', ['url' => verifierSourceUrl('openai')]),
        new ToolCall((string) Str::ulid(), 'UpsertModelPriceTool', [
            'provider' => 'openai', 'model' => 'gpt-should-not-write',
            'input_per_mtok' => 3.0, 'output_per_mtok' => 6.0,
            'source_url' => verifierSourceUrl('openai'), 'source_updated_at' => null,
        ]),
        new ToolCall((string) Str::ulid(), 'UpsertModelPriceTool', [
            'provider' => 'openai', 'model' => 'gpt-anom',
            'input_per_mtok' => 3.0, 'output_per_mtok' => 2.0,
            'source_url' => verifierSourceUrl('openai'), 'source_updated_at' => null,
        ]),
        new ToolCall((string) Str::ulid(), 'WebFetchTool', ['url' => verifierSourceUrl('anthropic')]),
        new ToolCall((string) Str::ulid(), 'UpsertModelPriceTool', [
            'provider' => 'anthropic', 'model' => 'claude-wide',
            'input_per_mtok' => 3.0, 'output_per_mtok' => 15.0,
            'source_url' => verifierSourceUrl('anthropic'), 'source_updated_at' => null,
        ]),
        'done',
    ]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    // The out-of-scope OpenAI model was rejected by the scope; the anomalous
    // one was corrected; the wildcard Anthropic model landed.
    expect(AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-should-not-write')->exists())->toBeFalse()
        ->and((string) AiModelPrice::query()->where('model', 'gpt-anom')->firstOrFail()->input_per_mtok)->toBe('3.0000')
        ->and(AiModelPrice::query()->where('provider', 'anthropic')->where('model', 'claude-wide')->exists())->toBeTrue();

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and($refreshReport->fallbackProviders)->toContain('anthropic')
        ->and($refreshReport->fallbackProviders)->toContain('openai');

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    // The wildcard Anthropic target resolved through any real provider write;
    // the exact-model OpenAI target resolved through its own model write, so
    // nothing is left unverified.
    expect($aiPriceRefreshRun->fallback_targets)->toContain('anthropic')
        ->and($aiPriceRefreshRun->fallback_targets)->toContain('openai:gpt-anom')
        ->and($aiPriceRefreshRun->unverified_targets)->toBeNull();
});

test('a failed anomaly verification preserves the stored value and the provider stays resolved', function (): void {
    PriceFetcherAgent::fake(fn (): never => throw new RuntimeException('verifier exploded'));

    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-anom', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);

    fakeFeed(['openai' => ['models' => ['gpt-anom' => feedModel(10.0, 2.0)]]]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai']));

    // The provider resolved through the feed and keeps its ok status, but the
    // exact-model anomaly target was never verified, so the run degrades to
    // partial and audits the unaddressed pair.
    expect((string) AiModelPrice::query()->where('model', 'gpt-anom')->firstOrFail()->input_per_mtok)->toBe('1.0000');

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_PARTIAL)
        ->and($refreshReport->providersSucceeded)->toBe(1)
        ->and($refreshReport->errorMessage)->toContain('verifier exploded');

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->unverified_targets)->toBe(['openai:gpt-anom'])
        ->and($aiPriceRefreshRun->provider_results['openai']['status'])->toBe('ok');
});

test('the models-dev source records anomaly targets without invoking the agent', function (): void {
    PriceFetcherAgent::fake(['ok']);

    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-anom', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);

    fakeFeed(['openai' => ['models' => ['gpt-anom' => feedModel(10.0, 2.0)]]]);

    $refreshReport = runCoordinator(
        source: AiPriceRefreshCoordinator::SOURCE_MODELS_DEV,
        scope: RefreshScope::forProviders(['openai']),
    );

    PriceFetcherAgent::assertNeverPrompted();

    expect((string) AiModelPrice::query()->where('model', 'gpt-anom')->firstOrFail()->input_per_mtok)->toBe('1.0000')
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and($refreshReport->fallbackProviders)->toBe(['openai']);

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->fallback_targets)->toBe(['openai:gpt-anom'])
        ->and($aiPriceRefreshRun->provider_results['openai']['anomalous'])->toBe(1);
});

test('a provider with zero eligible candidates is incomplete and falls back', function (): void {
    fakeVerifierWrites(['openai' => ['gpt-verify']]);

    fakeFeed([
        'openai' => ['models' => [
            'gpt-old-a' => feedModel(extra: ['deprecated' => true]),
            'embedding-3' => feedModel(extra: ['modalities' => ['output' => ['embedding']]]),
        ]],
        'anthropic' => ['models' => ['claude-feed' => feedModel()]],
    ]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    // The feed answered nothing usable for OpenAI, so the verifier re-reads it.
    PriceFetcherAgent::assertPrompted(function (AgentPrompt $agentPrompt): bool {
        $instructions = (string) $agentPrompt->agent->instructions();

        return str_contains($instructions, 'https://developers.openai.com/api/docs/pricing')
            && ! str_contains($instructions, 'https://claude.com/pricing');
    });

    expect($refreshReport->fallbackProviders)->toBe(['openai'])
        ->and($refreshReport->modelsRejected)->toBe(2)
        ->and($refreshReport->providersSucceeded)->toBe(2)
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED);
});

test('an isolated provider write failure escalates to the verifier instead of failing terminally', function (): void {
    // The verifier is prompted but performs no verified write, so the isolated
    // failure stays unresolved (partial) rather than terminal write_failed.
    PriceFetcherAgent::fake(['could not read the openai page']);

    fakeFeed([
        'openai' => ['models' => [
            'gpt-ok' => feedModel(),
            'gpt-boom' => feedModel(),
        ]],
        'anthropic' => ['models' => ['claude-feed' => feedModel()]],
    ]);

    // QueryExecuted listeners run inside the connection; throwing here surfaces
    // as a mid-transaction failure for exactly one provider's slice.
    DB::listen(function ($query): void {
        throw_if(str_contains((string) $query->sql, 'ai_model_prices') && in_array('gpt-boom', $query->bindings, true), RuntimeException::class, 'mid-provider write exploded');
    });

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    // The isolated failure now queues the provider for first-party fallback.
    PriceFetcherAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => true);

    // OpenAI's whole feed slice rolled back — including the model written before
    // the failure — while Anthropic still landed.
    expect(AiModelPrice::query()->where('provider', 'openai')->count())->toBe(0)
        ->and(AiModelPrice::query()->where('provider', 'anthropic')->where('model', 'claude-feed')->exists())->toBeTrue();

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_PARTIAL)
        ->and($refreshReport->providersSucceeded)->toBe(1)
        ->and($refreshReport->providersFailed)->toBe(1)
        ->and($refreshReport->modelsCreated)->toBe(1)
        ->and($refreshReport->fallbackProviders)->toBe(['openai'])
        ->and($refreshReport->errorMessage)->toContain('mid-provider write exploded');

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    // Unresolved because the agent produced no verified write — not terminal.
    expect($aiPriceRefreshRun->provider_results['openai']['status'])->toBe('fallback_failed');
});

test('an isolated provider write failure resolves when the verifier writes it', function (): void {
    // Same isolated failure, but this time the verifier produces a real write,
    // so the provider is resolved and the run fully succeeds.
    fakeVerifierWrites(['openai' => ['gpt-recovered']]);

    fakeFeed([
        'openai' => ['models' => [
            'gpt-ok' => feedModel(),
            'gpt-boom' => feedModel(),
        ]],
        'anthropic' => ['models' => ['claude-feed' => feedModel()]],
    ]);

    DB::listen(function ($query): void {
        throw_if(str_contains((string) $query->sql, 'ai_model_prices') && in_array('gpt-boom', $query->bindings, true), RuntimeException::class, 'mid-provider write exploded');
    });

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and($refreshReport->providersSucceeded)->toBe(2)
        ->and($refreshReport->providersFailed)->toBe(0)
        ->and($refreshReport->fallbackProviders)->toBe(['openai']);

    // The verifier's first-party row landed even though the feed slice failed.
    expect(AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-recovered')->exists())->toBeTrue();

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->provider_results['openai']['status'])->toBe('fallback');
});

test('a connection-level failure fails the whole run without invoking the verifier', function (): void {
    PriceFetcherAgent::fake(['ok']);

    fakeFeed([
        'openai' => ['models' => ['gpt-ok' => feedModel(), 'gpt-boom' => feedModel()]],
        'anthropic' => ['models' => ['claude-feed' => feedModel()]],
    ]);

    // A lost-connection message aborts the whole run: the database is unusable,
    // so the verifier (which also writes to it) must never run.
    DB::listen(function ($query): void {
        throw_if(str_contains((string) $query->sql, 'ai_model_prices') && in_array('gpt-boom', $query->bindings, true), RuntimeException::class, 'server has gone away');
    });

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    PriceFetcherAgent::assertNeverPrompted();

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_FAILED)
        ->and($refreshReport->errorMessage)->toContain('server has gone away');
});

test('a dry run records anomaly verification targets without invoking the agent', function (): void {
    PriceFetcherAgent::fake(['ok']);

    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-anom', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);

    fakeFeed(['openai' => ['models' => ['gpt-anom' => feedModel(10.0, 2.0)]]]);

    $refreshReport = runCoordinator(
        mode: AiPriceRefreshCoordinator::MODE_DRY_RUN,
        scope: RefreshScope::forProviders(['openai']),
    );

    PriceFetcherAgent::assertNeverPrompted();

    expect((string) AiModelPrice::query()->where('model', 'gpt-anom')->firstOrFail()->input_per_mtok)->toBe('1.0000')
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and($refreshReport->fallbackProviders)->toBe(['openai']);

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->fallback_targets)->toBe(['openai:gpt-anom']);
});

test('a dry run writes no catalog rows and never invokes the agent', function (): void {
    PriceFetcherAgent::fake(['ok']);

    fakeFeed(['openai' => ['models' => ['gpt-feed' => feedModel()]]]);

    $refreshReport = runCoordinator(
        mode: AiPriceRefreshCoordinator::MODE_DRY_RUN,
        scope: RefreshScope::forProviders(['openai', 'anthropic']),
    );

    PriceFetcherAgent::assertNeverPrompted();

    expect(AiModelPrice::query()->count())->toBe(0)
        ->and($refreshReport->modelsCreated)->toBe(1)
        ->and($refreshReport->fallbackProviders)->toBe(['anthropic'])
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_PARTIAL);

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->mode)->toBe(AiPriceRefreshCoordinator::MODE_DRY_RUN);
});

test('an agent failure after a successful feed slice yields a partial run', function (): void {
    PriceFetcherAgent::fake(fn (): never => throw new RuntimeException('agent exploded'));

    fakeFeed([
        'openai' => ['models' => ['gpt-feed' => feedModel()]],
        'anthropic' => ['models' => 'not-a-map'],
    ]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai', 'anthropic']));

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_PARTIAL)
        ->and($refreshReport->providersSucceeded)->toBe(1)
        ->and($refreshReport->providersFailed)->toBe(1)
        ->and($refreshReport->errorMessage)->toContain('agent exploded')
        ->and(AiModelPrice::query()->where('provider', 'openai')->exists())->toBeTrue();
});

test('the budget guard blocks the agent run before any prompt is sent', function (): void {
    PriceFetcherAgent::fake(['ok']);

    AiModelPrice::create([
        'provider' => 'openai',
        'model' => 'spend-model',
        'input_per_mtok' => 1.0,
        'output_per_mtok' => 2.0,
    ]);
    AiUsageRecord::factory()->create([
        'provider' => 'openai',
        'model' => 'spend-model',
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 1_000_000,
    ]);
    resolve(AiSettings::class)->setHardBudgetUsd(1.0);

    $refreshReport = runCoordinator(
        source: AiPriceRefreshCoordinator::SOURCE_AGENT,
        scope: RefreshScope::forProviders(['anthropic']),
    );

    PriceFetcherAgent::assertNeverPrompted();

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_FAILED)
        ->and($refreshReport->errorMessage)->not->toBeNull()
        ->and($refreshReport->providersFailed)->toBe(1);
});

test('run counts reflect every write outcome and tier warnings', function (): void {
    PriceFetcherAgent::fake(['ok']);

    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-updated', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);
    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-unchanged', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);
    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-locked', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0, 'is_price_locked' => true]);

    fakeFeed([
        'openai' => ['models' => [
            'gpt-updated' => feedModel(2.0, 4.0),
            'gpt-unchanged' => feedModel(1.0, 2.0),
            'gpt-locked' => feedModel(3.0, 3.0),
            'gpt-new' => feedModel(),
            'gpt-dep' => feedModel(extra: ['deprecated' => true]),
            'gpt-tiered' => feedModel(extra: ['cost' => ['context_over_200k_input' => 2.5]]),
        ]],
    ]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai']));

    expect($refreshReport->modelsCreated)->toBe(2)
        ->and($refreshReport->modelsUpdated)->toBe(1)
        ->and($refreshReport->modelsUnchanged)->toBe(1)
        ->and($refreshReport->modelsLocked)->toBe(1)
        ->and($refreshReport->modelsRejected)->toBe(1)
        ->and($refreshReport->modelsTiered)->toBe(1);

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->models_created)->toBe(2)
        ->and($aiPriceRefreshRun->models_updated)->toBe(1)
        ->and($aiPriceRefreshRun->models_unchanged)->toBe(1)
        ->and($aiPriceRefreshRun->models_locked)->toBe(1)
        ->and($aiPriceRefreshRun->models_rejected)->toBe(1)
        ->and($aiPriceRefreshRun->models_tiered)->toBe(1)
        ->and($aiPriceRefreshRun->provider_results)->toHaveKey('openai');

    // The audit row stores compact counters and codes, never raw feed payloads.
    $encoded = json_encode($aiPriceRefreshRun->provider_results, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('cost')
        ->and($encoded)->not->toContain('gpt-updated');
});

test('historical usage snapshots are never mutated by a refresh', function (): void {
    PriceFetcherAgent::fake(['ok']);

    AiModelPrice::create(['provider' => 'openai', 'model' => 'gpt-snap', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);

    $usage = AiUsageRecord::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-snap',
        'input_per_mtok' => '1.0000',
        'output_per_mtok' => '2.0000',
        'price_source' => 'live',
    ]);

    fakeFeed(['openai' => ['models' => ['gpt-snap' => feedModel(2.0, 4.0)]]]);

    runCoordinator(scope: RefreshScope::forProviders(['openai']));

    expect((string) AiModelPrice::query()->where('model', 'gpt-snap')->firstOrFail()->input_per_mtok)->toBe('2.0000');

    $usage->refresh();
    expect((string) $usage->input_per_mtok)->toBe('1.0000')
        ->and((string) $usage->output_per_mtok)->toBe('2.0000')
        ->and($usage->price_source)->toBe('live');
});

test('the run records mode, trigger, and the triggering user', function (): void {
    PriceFetcherAgent::fake(['ok']);

    fakeFeed(['openai' => ['models' => ['gpt-feed' => feedModel()]]]);

    $user = User::factory()->admin()->create();

    $refreshReport = runCoordinator(
        scope: RefreshScope::forProviders(['openai']),
        triggeredBy: $user,
        trigger: 'manual',
    );

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);

    expect($aiPriceRefreshRun->mode)->toBe(AiPriceRefreshCoordinator::MODE_APPLY)
        ->and($aiPriceRefreshRun->trigger)->toBe('manual')
        ->and($aiPriceRefreshRun->triggered_by_user_id)->toBe($user->id)
        ->and($aiPriceRefreshRun->providers_requested)->toBe(1)
        ->and($aiPriceRefreshRun->providers_succeeded)->toBe(1);
});

test('verify mode runs the feed then verifies every scoped provider', function (): void {
    // Feed-then-verify (spec §14.4): the feed syncs the row, then the verifier
    // re-reads the first-party page and confirms it. The provider resolves only
    // because the agent produced a real verified write for it.
    fakeVerifierWrites(['openai' => ['gpt-feed']]);

    fakeFeed(['openai' => ['models' => ['gpt-feed' => feedModel()]]]);

    $refreshReport = runCoordinator(
        mode: AiPriceRefreshCoordinator::MODE_VERIFY,
        scope: RefreshScope::forProviders(['openai']),
    );

    PriceFetcherAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => true);

    expect($refreshReport->mode)->toBe(AiPriceRefreshCoordinator::MODE_VERIFY)
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and($refreshReport->fallbackProviders)->toContain('openai')
        ->and(AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-feed')->exists())->toBeTrue();

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->mode)->toBe(AiPriceRefreshCoordinator::MODE_VERIFY)
        ->and($aiPriceRefreshRun->provider_results['openai']['status'])->toBe('fallback');
});

test('a verify target the agent never confirms is left unresolved', function (): void {
    // The feed resolves both providers, but verify makes each depend on a
    // verification-grade agent write. The verifier confirms only openai
    // (receipt-backed); anthropic stays an unverified verify target, so the run
    // degrades to partial and its uncovered stored row is audited.
    fakePricingPages();

    PriceFetcherAgent::fake([
        new ToolCall((string) Str::ulid(), 'WebFetchTool', ['url' => verifierSourceUrl('openai')]),
        new ToolCall((string) Str::ulid(), 'UpsertModelPriceTool', [
            'provider' => 'openai', 'model' => 'gpt-feed',
            'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0,
            'source_url' => verifierSourceUrl('openai'), 'source_updated_at' => null,
        ]),
        'done',
    ]);

    fakeFeed([
        'openai' => ['models' => ['gpt-feed' => feedModel()]],
        'anthropic' => ['models' => ['claude-feed' => feedModel()]],
    ]);

    $refreshReport = runCoordinator(
        mode: AiPriceRefreshCoordinator::MODE_VERIFY,
        scope: RefreshScope::forProviders(['openai', 'anthropic']),
    );

    expect($refreshReport->mode)->toBe(AiPriceRefreshCoordinator::MODE_VERIFY)
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_PARTIAL)
        ->and($refreshReport->providersSucceeded)->toBe(1)
        ->and($refreshReport->providersFailed)->toBe(1);

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->provider_results['openai']['status'])->toBe('fallback')
        ->and($aiPriceRefreshRun->provider_results['anthropic']['status'])->toBe('fallback_failed')
        ->and($aiPriceRefreshRun->unverified_targets)->toBe(['anthropic:claude-feed']);
});

test('verify mode records the first-party discrepancy count against the synced feed', function (): void {
    // The feed syncs gpt-x at 2/4; the verifier re-reads first-party and writes
    // 1/2 — a plausible first-party value that differs from the just-synced
    // feed (the provider-native fake cannot bypass the anomaly guard), so one
    // discrepancy is recorded for the provider.
    fakeVerifierWrites(['openai' => ['gpt-x']]);

    fakeFeed(['openai' => ['models' => ['gpt-x' => feedModel(2.0, 4.0)]]]);

    $refreshReport = runCoordinator(
        mode: AiPriceRefreshCoordinator::MODE_VERIFY,
        scope: RefreshScope::forProviders(['openai']),
    );

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED);

    // The verifier's first-party rate overwrote the feed value.
    expect((string) AiModelPrice::query()->where('model', 'gpt-x')->firstOrFail()->input_per_mtok)->toBe('1.0000');

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->provider_results['openai']['discrepancies'])->toBe(1)
        ->and($aiPriceRefreshRun->provider_results['openai']['status'])->toBe('fallback');
});

test('a verify dry run runs the agent with dry-run persistence and resolves from the real comparison', function (): void {
    // `--verify --dry-run` genuinely verifies: the feed dry-runs (no rows
    // written) and the agent DOES run with dry-run persistence threaded through
    // — real fetch, real parse, real first-party comparison, zero writes and
    // zero verified stamps. The receipt-backed WouldCreate outcome is
    // verification-grade, so the target genuinely resolves and the dry verify
    // reads succeeded from the actual comparison.
    fakeVerifierWrites(['openai' => ['gpt-feed']]);

    fakeFeed(['openai' => ['models' => ['gpt-feed' => feedModel()]]]);

    $refreshReport = runCoordinator(
        mode: AiPriceRefreshCoordinator::MODE_VERIFY,
        scope: RefreshScope::forProviders(['openai']),
        dryRun: true,
    );

    PriceFetcherAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => true);

    // Nothing persisted anywhere: no catalog rows, no verification stamps.
    expect(AiModelPrice::query()->count())->toBe(0)
        ->and($refreshReport->mode)->toBe(AiPriceRefreshCoordinator::MODE_VERIFY)
        ->and($refreshReport->fallbackProviders)->toBe(['openai'])
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and($refreshReport->modelsCreated)->toBe(2); // 1 feed would-create + 1 agent would-create

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->mode)->toBe(AiPriceRefreshCoordinator::MODE_VERIFY)
        ->and($aiPriceRefreshRun->status)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and($aiPriceRefreshRun->fallback_targets)->toBe(['openai'])
        ->and($aiPriceRefreshRun->unverified_targets)->toBeNull()
        ->and($aiPriceRefreshRun->provider_results['openai']['status'])->toBe('fallback');
});

test('a verify dry run leaves a target the agent cannot confirm unresolved', function (): void {
    // The dry verify still reports honestly: a provider whose first-party
    // comparison never happened (no verification-grade outcome) stays
    // unresolved and degrades the dry run to partial.
    fakePricingPages();
    PriceFetcherAgent::fake(['could not read the pricing page']);

    fakeFeed(['openai' => ['models' => ['gpt-feed' => feedModel()]]]);

    $refreshReport = runCoordinator(
        mode: AiPriceRefreshCoordinator::MODE_VERIFY,
        scope: RefreshScope::forProviders(['openai']),
        dryRun: true,
    );

    PriceFetcherAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => true);

    expect(AiModelPrice::query()->count())->toBe(0)
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_FAILED);

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);
    expect($aiPriceRefreshRun->provider_results['openai']['status'])->toBe('fallback_failed');
});

test('verify with the agent source verifies without running the feed', function (): void {
    fakeVerifierWrites(['openai' => ['gpt-verify']]);
    Http::fake();

    $refreshReport = runCoordinator(
        mode: AiPriceRefreshCoordinator::MODE_VERIFY,
        source: AiPriceRefreshCoordinator::SOURCE_AGENT,
        scope: RefreshScope::forProviders(['openai']),
    );

    // The verifier legitimately fetches first-party pricing pages; only the
    // Models.dev feed must be untouched on these feed-less paths.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'models.dev'));
    PriceFetcherAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => true);

    expect($refreshReport->modelsDevStatus)->toBe('skipped')
        ->and($refreshReport->fallbackProviders)->toBe(['openai'])
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED);
});

test('the report exposes a broadcast array and console lines', function (): void {
    PriceFetcherAgent::fake(['ok']);

    fakeFeed(['openai' => ['models' => ['gpt-feed' => feedModel()]]]);

    $refreshReport = runCoordinator(scope: RefreshScope::forProviders(['openai']));

    $broadcast = $refreshReport->toBroadcastArray();

    expect($broadcast)->toHaveKeys([
        'run_id',
        'final_result',
        'models_dev_status',
        'providers_requested',
        'providers_succeeded',
        'providers_failed',
        'models_created',
        'models_updated',
        'models_unchanged',
        'models_locked',
        'models_rejected',
        'models_tiered',
        'fallback_providers',
        'error_message',
    ])
        ->and($broadcast['run_id'])->toBe($refreshReport->runId)
        ->and($broadcast['final_result'])->toBe(RefreshReport::RESULT_SUCCEEDED);

    $lines = $refreshReport->toConsoleLines();

    expect($lines)->toBeArray()->not->toBeEmpty()
        ->and(implode("\n", $lines))->toContain('succeeded');
});
