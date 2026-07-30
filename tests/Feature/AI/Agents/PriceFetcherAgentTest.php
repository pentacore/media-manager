<?php

declare(strict_types=1);

use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\Tools\PriceFetcher\UpsertModelPriceTool;
use App\Ai\Tools\PriceFetcher\WebFetchTool;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Services\AiUsage\Pricing\RefreshScope;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Providers\Tools\WebFetch;

/**
 * Read the private per-run scope off the agent's resolved upsert tool so the
 * tests can assert scope propagation without executing a live write.
 */
function priceFetcherUpsertScope(PriceFetcherAgent $agent): RefreshScope
{
    $upsert = collect($agent->tools())
        ->first(fn (object $tool): bool => $tool instanceof UpsertModelPriceTool);

    expect($upsert)->toBeInstanceOf(UpsertModelPriceTool::class);

    /** @var RefreshScope $scope */
    $scope = new ReflectionProperty(UpsertModelPriceTool::class, 'scope')->getValue($upsert);

    return $scope;
}

test('agent declares both pricing tools', function (): void {
    $agent = new PriceFetcherAgent;

    expect($agent)->toBeInstanceOf(HasTools::class);

    $names = collect($agent->tools())->map(fn ($tool): string => $tool::class)->all();
    expect($names)
        ->toContain(WebFetchTool::class)
        ->toContain(UpsertModelPriceTool::class);
});

test('price fetcher uses provider webfetch when flag enabled', function (): void {
    config()->set('mediamanager.ai.price_fetcher_provider_webfetch', true);

    $tools = collect((new PriceFetcherAgent)->tools())->map(fn (object $tool): string => $tool::class);

    expect($tools)->toContain(WebFetch::class)
        ->not->toContain(WebFetchTool::class);
});

test('price fetcher defaults to the custom webfetch tool', function (): void {
    $tools = collect((new PriceFetcherAgent)->tools())->map(fn (object $tool): string => $tool::class);

    expect($tools)->toContain(WebFetchTool::class);
});

test('admin refresh endpoint queues the agent and toasts the queued state', function (): void {
    PriceFetcherAgent::fake(['Refreshed 6 OpenAI models, skipped DeepSeek (fetch_failed).']);

    $admin = User::factory()->admin()->create();

    // Sync queue (phpunit.xml) runs the job inline, so the agent still
    // executes inside this POST. The flashed toast now reports "queued"
    // because the controller no longer waits for the agent — progress is
    // surfaced via the admin.ai-prices broadcast channel instead.
    $this->actingAs($admin)
        ->post(route('admin.ai-prices.refresh'))
        ->assertRedirect(route('admin.ai-prices.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success')
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message): bool => str_contains($message, 'queued'));
});

test('non-admins cannot trigger a price refresh', function (): void {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->post(route('admin.ai-prices.refresh'))
        ->assertForbidden();
});

test('refresh endpoint reports count delta against the database', function (): void {
    PriceFetcherAgent::fake(['done']);

    AiModelPrice::factory()->count(2)->create();

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.refresh'))
        ->assertRedirect(route('admin.ai-prices.index'));

    // The fake doesn't insert rows, so :added should be zero — confirms we
    // aren't crashing on an empty agent run.
    expect(AiModelPrice::query()->count())->toBe(2);
});

test('refresh attributes AI usage to the admin who triggered it', function (): void {
    PriceFetcherAgent::fake(['done']);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.refresh'))
        ->assertRedirect(route('admin.ai-prices.index'));

    $record = AiUsageRecord::query()
        ->where('agent_class', PriceFetcherAgent::class)
        ->latest('id')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->user_id)->toBe($admin->id);
});

test('refresh captures a price snapshot onto the usage row', function (): void {
    AiModelPrice::create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 0.50,
        'output_per_mtok' => 2.00,
        'cache_read_per_mtok' => 0.10,
        'cache_write_per_mtok' => 0.40,
        'reasoning_per_mtok' => 1.00,
    ]);

    PriceFetcherAgent::fake(['done']);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.refresh'));

    $aiUsageRecord = AiUsageRecord::query()
        ->where('agent_class', PriceFetcherAgent::class)
        ->latest('id')
        ->firstOrFail();

    expect($aiUsageRecord->price_source)->toBe('live')
        ->and((float) $aiUsageRecord->input_per_mtok)->toBe(0.50)
        ->and((float) $aiUsageRecord->reasoning_per_mtok)->toBe(1.00);
});

test('refresh leaves snapshot null when the agent model is unpriced', function (): void {
    PriceFetcherAgent::fake(['done']);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.refresh'));

    $aiUsageRecord = AiUsageRecord::query()
        ->where('agent_class', PriceFetcherAgent::class)
        ->latest('id')
        ->firstOrFail();

    expect($aiUsageRecord->price_source)->toBeNull()
        ->and($aiUsageRecord->input_per_mtok)->toBeNull();
});

test('forScope propagates the run scope into the upsert tool', function (): void {
    $refreshScope = RefreshScope::forProviderModels(['openai' => ['gpt-5-mini']]);

    $priceFetcherAgent = (new PriceFetcherAgent)->forScope($refreshScope, ['openai']);

    $toolScope = priceFetcherUpsertScope($priceFetcherAgent);

    expect($toolScope->allowsWrite('openai', 'gpt-5-mini'))->toBeTrue()
        ->and($toolScope->allowsWrite('openai', 'gpt-4o'))->toBeFalse()
        ->and($toolScope->allowsWrite('anthropic', 'claude-sonnet-4-6'))->toBeFalse();
});

test('unscoped agent fails closed with a deny-all write scope', function (): void {
    $refreshScope = priceFetcherUpsertScope(new PriceFetcherAgent);

    expect($refreshScope->allowsProvider('openai'))->toBeFalse()
        ->and($refreshScope->allowsWrite('openai', 'gpt-5-mini'))->toBeFalse();
});

test('repeated scope assignments do not leak between tool resolutions', function (): void {
    $agent = new PriceFetcherAgent;

    $agent->forScope(RefreshScope::forProviderModels(['openai' => ['gpt-5-mini']]), ['openai']);

    $refreshScope = priceFetcherUpsertScope($agent);

    $agent->forScope(RefreshScope::forProviderModels(['anthropic' => ['claude-sonnet-4-6']]), ['anthropic']);
    $second = priceFetcherUpsertScope($agent);

    // The clone handed to the first resolution keeps its own scope; the later
    // reassignment must not mutate it (Octane-shared-instance safety).
    expect($refreshScope->allowsWrite('openai', 'gpt-5-mini'))->toBeTrue()
        ->and($refreshScope->allowsWrite('anthropic', 'claude-sonnet-4-6'))->toBeFalse();

    // The second resolution reflects only the latest scope.
    expect($second->allowsWrite('anthropic', 'claude-sonnet-4-6'))->toBeTrue()
        ->and($second->allowsWrite('openai', 'gpt-5-mini'))->toBeFalse();
});

test('verifier instructions state the fetch-before-write contract', function (): void {
    $priceFetcherAgent = (new PriceFetcherAgent)->forScope(
        RefreshScope::forProviders(['openai', 'gemini']),
        ['openai', 'gemini'],
    );

    $instructions = (string) $priceFetcherAgent->instructions();
    $lower = strtolower($instructions);

    expect($lower)
        ->toContain('verifier')          // declares its verifier role
        ->toContain('fetch the page first') // fetch-before-write
        ->toContain('missing')           // unknown => missing
        ->toContain('null')              // pass null for unreadable tiers
        ->toContain('scope')             // out-of-scope writes forbidden
        ->toContain('source_url')        // provenance URL required per write
        ->toContain('source_updated_at'); // page-stated date or null
    expect($instructions)->toContain('gemini'); // canonical Google identity
});

test('unscoped instructions tell the agent to do nothing instead of listing every provider', function (): void {
    $instructions = (string) (new PriceFetcherAgent)->instructions();

    expect($instructions)
        ->toContain('no providers in scope')
        ->toContain('Do not fetch any pages')
        ->not->toContain('https://developers.openai.com/api/docs/pricing')
        ->not->toContain('https://ai.google.dev/gemini-api/docs/pricing');
});

test('a scope resolving to zero known providers also yields the do-nothing prompt', function (): void {
    $priceFetcherAgent = (new PriceFetcherAgent)->forScope(
        RefreshScope::forProviders(['vertex']),
        ['vertex'],
    );

    expect((string) $priceFetcherAgent->instructions())->toContain('no providers in scope');
});

test('verifier instructions target only the scoped providers and canonical urls', function (): void {
    $priceFetcherAgent = (new PriceFetcherAgent)->forScope(
        RefreshScope::forProviderModels(['google' => ['gemini-2.5-pro']]),
        ['google'],
    );

    $instructions = (string) $priceFetcherAgent->instructions();

    expect($instructions)
        ->toContain('gemini-2.5-pro')                                       // targeted model list
        ->toContain('https://ai.google.dev/gemini-api/docs/pricing')        // canonical source url
        ->not->toContain('https://developers.openai.com/api/docs/pricing')  // untargeted provider excluded
        ->not->toContain('https://claude.com/pricing');
});

test('a wildcard provider renders its stored-model checklist without narrowing the focus', function (): void {
    // A provider-wide verification target lists the currently-stored models as
    // a re-confirmation checklist while keeping the whole-catalog focus, so new
    // models stay in play and coverage of stored rows is achievable.
    $priceFetcherAgent = (new PriceFetcherAgent)->forScope(
        RefreshScope::forTargets(['openai' => null]),
        ['openai'],
        ['openai' => ['gpt-5-mini', 'gpt-5-nano']],
    );

    $instructions = (string) $priceFetcherAgent->instructions();

    expect($instructions)
        ->toContain('every generally-available text/chat model it publishes')
        ->toContain('at minimum re-confirm each currently-stored model: gpt-5-mini, gpt-5-nano');
});

test('an exact-model scope wins over a checklist and renders only the pinned models', function (): void {
    $priceFetcherAgent = (new PriceFetcherAgent)->forScope(
        RefreshScope::forProviderModels(['openai' => ['gpt-anom']]),
        ['openai'],
        ['openai' => ['gpt-5-mini', 'gpt-5-nano']],
    );

    $instructions = (string) $priceFetcherAgent->instructions();

    expect($instructions)
        ->toContain('only these models: gpt-anom')
        ->not->toContain('re-confirm each currently-stored model');
});

test('verifier keeps the 40-step ceiling', function (): void {
    $attributes = new ReflectionClass(PriceFetcherAgent::class)->getAttributes(MaxSteps::class);

    expect($attributes)->toHaveCount(1)
        ->and($attributes[0]->newInstance()->value)->toBe(40);
});

test('agent is a tool provider', function (): void {
    expect(new PriceFetcherAgent)->toBeInstanceOf(HasTools::class);
});
