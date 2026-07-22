<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\PriceFetcher\UpsertModelPriceTool;
use App\Enums\PricingSource;
use App\Models\AiModelPrice;
use App\Services\AiUsage\Pricing\PriceVerificationRun;
use App\Services\AiUsage\Pricing\RefreshScope;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

/**
 * Resolve the tool through the container so its writer dependency is injected,
 * then apply the given scope. Unscoped resolution is used only to prove the
 * deny-all default.
 */
function upsertTool(?RefreshScope $scope = null): UpsertModelPriceTool
{
    $tool = app(UpsertModelPriceTool::class);

    return $scope === null ? $tool : $tool->withScope($scope);
}

/**
 * @param  array<string, mixed>  $args
 * @return array<string, mixed>
 */
function upsert(UpsertModelPriceTool $tool, array $args): array
{
    return json_decode($tool->handle(new Request($args)), true);
}

test('creates a new price row when within scope', function (): void {
    $result = upsert(upsertTool(RefreshScope::all()), [
        'provider' => 'openai',
        'model' => 'gpt-x-test',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5,
    ]);

    expect($result['upserted'])->toBeTrue();

    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-x-test')->firstOrFail();
    expect((float) $row->input_per_mtok)->toBe(1.25)
        ->and((float) $row->output_per_mtok)->toBe(5.0)
        ->and($row->pricing_source)->toBe(PricingSource::FirstParty);
});

test('updates the existing row in place rather than creating a duplicate', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'anthropic',
        'model' => 'claude-test',
        'input_per_mtok' => 2.0,
        'output_per_mtok' => 10.0,
    ]);

    upsert(upsertTool(RefreshScope::all()), [
        'provider' => 'anthropic',
        'model' => 'claude-test',
        'input_per_mtok' => 3.0,
        'output_per_mtok' => 15.0,
        'cache_read_per_mtok' => 0.3,
        'cache_write_per_mtok' => 3.75,
    ]);

    $rows = AiModelPrice::query()->where('provider', 'anthropic')->where('model', 'claude-test')->get();
    expect($rows)->toHaveCount(1);
    expect((float) $rows[0]->input_per_mtok)->toBe(3.0)
        ->and((float) $rows[0]->cache_write_per_mtok)->toBe(3.75);
});

test('rejects an empty provider or model with invalid_args', function (): void {
    $result = upsert(upsertTool(RefreshScope::all()), [
        'provider' => '',
        'model' => 'gpt-x',
        'input_per_mtok' => 1,
        'output_per_mtok' => 1,
    ]);

    expect($result['error'])->toBe('invalid_args')
        ->and(AiModelPrice::count())->toBe(0);
});

test('an unscoped tool defaults to deny-all and rejects the write', function (): void {
    $result = upsert(upsertTool(), [
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
    ]);

    expect($result['error'])->toBe('write_rejected')
        ->and(AiModelPrice::count())->toBe(0);
});

test('rejects a provider outside the run scope', function (): void {
    $result = upsert(upsertTool(RefreshScope::forProviders(['anthropic'])), [
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
    ]);

    expect($result['error'])->toBe('write_rejected')
        ->and(AiModelPrice::count())->toBe(0);
});

test('canonicalizes google to gemini on write', function (): void {
    $result = upsert(upsertTool(RefreshScope::all()), [
        'provider' => 'google',
        'model' => 'gemini-2.5-pro',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
    ]);

    expect($result['upserted'])->toBeTrue()
        ->and($result['provider'])->toBe('gemini');

    expect(AiModelPrice::query()->where('provider', 'gemini')->where('model', 'gemini-2.5-pro')->exists())->toBeTrue()
        ->and(AiModelPrice::query()->where('provider', 'google')->exists())->toBeFalse();
});

test('null price fields preserve existing values', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 2.0,
        'output_per_mtok' => 10.0,
        'cache_read_per_mtok' => 0.3,
    ]);

    upsert(upsertTool(RefreshScope::all()), [
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 3.0,
        'output_per_mtok' => 12.0,
        'cache_read_per_mtok' => null,
    ]);

    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-x')->firstOrFail();
    expect((float) $row->input_per_mtok)->toBe(3.0)
        ->and((float) $row->cache_read_per_mtok)->toBe(0.3);
});

test('an explicit zero writes a zero rate while a missing rate stays null', function (): void {
    upsert(upsertTool(RefreshScope::all()), [
        'provider' => 'openai',
        'model' => 'gpt-zero',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'batch_input_per_mtok' => 0,
        'batch_output_per_mtok' => null,
    ]);

    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-zero')->firstOrFail();
    expect((float) $row->batch_input_per_mtok)->toBe(0.0)
        ->and($row->batch_output_per_mtok)->toBeNull();
});

test('rejects a new row when a primary rate is missing', function (): void {
    $result = upsert(upsertTool(RefreshScope::all()), [
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => null,
        'output_per_mtok' => 5.0,
    ]);

    expect($result['error'])->toBe('write_rejected')
        ->and(AiModelPrice::count())->toBe(0);
});

test('skips a locked row', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 2.5,
        'output_per_mtok' => 10.0,
        'is_price_locked' => true,
    ]);

    $result = upsert(upsertTool(RefreshScope::all()), [
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 3.0,
        'output_per_mtok' => 12.0,
    ]);

    expect($result['error'])->toBe('price_locked');

    $row = AiModelPrice::query()->where('model', 'gpt-x')->firstOrFail();
    expect((float) $row->input_per_mtok)->toBe(2.5)
        ->and((float) $row->output_per_mtok)->toBe(10.0);
});

test('persists source metadata', function (): void {
    upsert(upsertTool(RefreshScope::all()), [
        'provider' => 'openai',
        'model' => 'gpt-src',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
        'source_updated_at' => '2026-07-01',
    ]);

    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-src')->firstOrFail();
    expect($row->pricing_source)->toBe(PricingSource::FirstParty)
        ->and($row->pricing_source_url)->toBe('https://developers.openai.com/api/docs/pricing')
        ->and($row->pricing_source_updated_at?->toDateString())->toBe('2026-07-01');
});

test('ignores a malformed or nonexistent source date without rejecting valid rates', function (string $sourceUpdatedAt): void {
    $model = 'gpt-date-'.md5($sourceUpdatedAt);

    $result = upsert(upsertTool(RefreshScope::all()), [
        'provider' => 'openai',
        'model' => $model,
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
        'source_updated_at' => $sourceUpdatedAt,
    ]);

    expect($result['upserted'])->toBeTrue();

    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', $model)->firstOrFail();
    expect((float) $row->input_per_mtok)->toBe(1.25)
        ->and($row->pricing_source_updated_at)->toBeNull();
})->with([
    'malformed date' => 'not-a-date',
    'nonexistent date' => '2026-02-30',
    'year zero' => '0000-01-01',
]);

test('accepts an anomalous update verified against the canonical first-party source', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-anomalous',
        'input_per_mtok' => 1.0,
        'output_per_mtok' => 4.0,
        'pricing_verified_at' => null,
    ]);

    // Verification-grade path: the run requires receipts and the exact
    // provider-matching page was genuinely fetched, so the anomaly guard is
    // bypassed and the row is stamped verified.
    $run = new PriceVerificationRun;
    $run->requireReceipts();
    $run->recordFetch('https://developers.openai.com/api/docs/pricing');

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-anomalous',
        'input_per_mtok' => 10.0,
        'output_per_mtok' => 40.0,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
    ]);

    expect($result['upserted'])->toBeTrue()
        ->and($result['outcome'])->toBe('updated');

    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-anomalous')->firstOrFail();
    expect((float) $row->input_per_mtok)->toBe(10.0)
        ->and((float) $row->output_per_mtok)->toBe(40.0)
        ->and($row->pricing_verified_at)->not->toBeNull();
});

test('stamps verification when first-party rates are unchanged', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-unchanged',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'pricing_verified_at' => null,
    ]);

    $run = new PriceVerificationRun;
    $run->requireReceipts();
    $run->recordFetch('https://developers.openai.com/api/docs/pricing');

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-unchanged',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
    ]);

    expect($result['upserted'])->toBeTrue()
        ->and($result['outcome'])->toBe('unchanged');

    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-unchanged')->firstOrFail();
    expect($row->pricing_verified_at)->not->toBeNull();
});

test('provider schema documents google canonicalization to gemini', function (): void {
    $schema = app(UpsertModelPriceTool::class)->schema(new JsonSchemaTypeFactory);

    expect($schema['provider']->toArray()['description'])
        ->toContain('google')
        ->toContain('gemini');
});

test('delegates the write to the shared writer rather than a bare updateOrCreate', function (): void {
    // A factory row carries a Legacy source and no synced/verified timestamps.
    // A bare updateOrCreate of the rate columns would leave that provenance
    // untouched; only the shared writer restamps it on a value-changing write.
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 2.0,
        'output_per_mtok' => 10.0,
        'pricing_source' => PricingSource::Legacy,
        'pricing_synced_at' => null,
    ]);

    $result = upsert(upsertTool(RefreshScope::all()), [
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 3.0,
        'output_per_mtok' => 12.0,
    ]);

    expect($result['upserted'])->toBeTrue();

    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-x')->firstOrFail();
    expect($row->pricing_source)->toBe(PricingSource::FirstParty)
        ->and($row->pricing_synced_at)->not->toBeNull()
        ->and((float) $row->input_per_mtok)->toBe(3.0);
});

test('risk is SafeWrite', function (): void {
    expect(app(UpsertModelPriceTool::class)->risk())->toBe(Risk::SafeWrite);
});

test('a receipt-gated run rejects a write with no matching fetch receipt', function (): void {
    $run = new PriceVerificationRun;
    $run->requireReceipts();

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
    ]);

    expect($result['error'])->toBe('unverified_source')
        ->and(AiModelPrice::count())->toBe(0);
});

test('a receipt-gated run accepts a write backed by an exact fetch receipt', function (): void {
    $run = new PriceVerificationRun;
    $run->requireReceipts();
    $run->recordFetch('https://developers.openai.com/api/docs/pricing');

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
    ]);

    expect($result['upserted'])->toBeTrue()
        ->and($run->providerHasVerifiedWrite('openai'))->toBeTrue();

    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-x')->firstOrFail();
    expect($row->pricing_verified_at)->not->toBeNull();
});

test('a run rejects a source_url whose host is off the allowlist', function (): void {
    $run = new PriceVerificationRun;
    $run->requireReceipts();
    $run->recordFetch('https://evil.example.com/pricing');

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'https://evil.example.com/pricing',
    ]);

    expect($result['error'])->toBe('unverified_source')
        ->and(AiModelPrice::count())->toBe(0);
});

test('a run rejects a non-http source_url scheme', function (): void {
    $run = new PriceVerificationRun;
    $run->requireReceipts();

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'javascript:alert(1)',
    ]);

    expect($result['error'])->toBe('unverified_source')
        ->and(AiModelPrice::count())->toBe(0);
});

test('the provider-native path writes best-effort without stamping or resolving verification', function (): void {
    // No requireReceipts(): the SDK's provider-native WebFetch leaves no local
    // receipt, so a write on this path can never prove a fetch happened. The
    // write still lands (the host-to-provider binding holds), but it is a
    // best-effort refresh, not verification: pricing_verified_at stays null,
    // and the ledger outcome is audit-only — it never resolves a
    // fallback/verification target at either granularity.
    $run = new PriceVerificationRun;

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
    ]);

    expect($result['upserted'])->toBeTrue()
        ->and($run->outcomesFor('openai'))->toHaveKey('created')
        ->and($run->providerHasVerifiedWrite('openai'))->toBeFalse()
        ->and($run->modelHasVerifiedWrite('openai', 'gpt-x'))->toBeFalse();

    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-x')->firstOrFail();
    expect($row->pricing_verified_at)->toBeNull();
});

test('the provider-native path cannot bypass the anomaly guard', function (): void {
    // Without a receipt the write is not first-party verified, so an anomalous
    // value on the provider-native path is rejected instead of bypassing the
    // guard, and the stored value (and its null verification stamp) survive.
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-anom-native',
        'input_per_mtok' => 1.0,
        'output_per_mtok' => 4.0,
        'pricing_verified_at' => null,
    ]);

    $run = new PriceVerificationRun;

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-anom-native',
        'input_per_mtok' => 10.0,
        'output_per_mtok' => 40.0,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
    ]);

    expect($result['error'])->toBe('write_rejected');

    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-anom-native')->firstOrFail();
    expect((float) $row->input_per_mtok)->toBe(1.0)
        ->and((float) $row->output_per_mtok)->toBe(4.0)
        ->and($row->pricing_verified_at)->toBeNull();
});

test('a run records the write outcome under the canonical provider', function (): void {
    // Receipt-backed run: only verification-grade writes resolve targets.
    $run = new PriceVerificationRun;
    $run->requireReceipts();
    $run->recordFetch('https://ai.google.dev/gemini-api/docs/pricing');

    upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'google',
        'model' => 'gemini-2.5-pro',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'https://ai.google.dev/gemini-api/docs/pricing',
    ]);

    // Recorded under the canonical `gemini`, not the upstream `google`.
    expect($run->providerHasVerifiedWrite('gemini'))->toBeTrue()
        ->and($run->outcomesFor('gemini'))->toHaveKey('created');

    // The outcome is also recorded per provider:model, so an exact-model
    // verification target resolves only through its own write — never through
    // another model of the same provider.
    expect($run->modelHasVerifiedWrite('gemini', 'gemini-2.5-pro'))->toBeTrue()
        ->and($run->modelHasVerifiedWrite('gemini', 'gemini-2.5-flash'))->toBeFalse();
});

test('an all-null tool call is rejected and resolves nothing', function (): void {
    // FIX 2: an all-null call supplies no rate fields at all — the writer
    // rejects it, nothing is stamped, and the ledger records no verified
    // outcome even on the fully receipt-backed path.
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-null-call',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'pricing_verified_at' => null,
    ]);

    $run = new PriceVerificationRun;
    $run->requireReceipts();
    $run->recordFetch('https://developers.openai.com/api/docs/pricing');

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-null-call',
        'input_per_mtok' => null,
        'output_per_mtok' => null,
        'cache_read_per_mtok' => null,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
    ]);

    expect($result['error'])->toBe('write_rejected')
        ->and($run->providerHasVerifiedWrite('openai'))->toBeFalse()
        ->and($run->modelHasVerifiedWrite('openai', 'gpt-null-call'))->toBeFalse();

    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-null-call')->firstOrFail();
    expect($row->pricing_verified_at)->toBeNull();
});

test('a receipt-backed cache-only update persists but stamps nothing and resolves nothing', function (): void {
    // FIX 2: a write that did not re-read BOTH primary rates is downgraded —
    // the update lands under normal merge rules, but pricing_verified_at stays
    // null and the ledger outcome is not verification-grade, so it cannot
    // resolve its verification target.
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-cache-only',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'cache_read_per_mtok' => 0.2,
        'pricing_verified_at' => null,
    ]);

    $run = new PriceVerificationRun;
    $run->requireReceipts();
    $run->recordFetch('https://developers.openai.com/api/docs/pricing');

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-cache-only',
        'input_per_mtok' => null,
        'output_per_mtok' => null,
        'cache_read_per_mtok' => 0.25,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
    ]);

    expect($result['upserted'])->toBeTrue()
        ->and($result['outcome'])->toBe('updated')
        ->and($run->providerHasVerifiedWrite('openai'))->toBeFalse()
        ->and($run->modelHasVerifiedWrite('openai', 'gpt-cache-only'))->toBeFalse();

    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-cache-only')->firstOrFail();
    expect((float) $row->cache_read_per_mtok)->toBe(0.25)
        ->and($row->pricing_verified_at)->toBeNull();
});

test('a full-rate receipt-backed unchanged write still stamps and resolves', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-full-same',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'pricing_verified_at' => null,
    ]);

    $run = new PriceVerificationRun;
    $run->requireReceipts();
    $run->recordFetch('https://developers.openai.com/api/docs/pricing');

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-full-same',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
    ]);

    expect($result['upserted'])->toBeTrue()
        ->and($result['outcome'])->toBe('unchanged')
        ->and($run->providerHasVerifiedWrite('openai'))->toBeTrue()
        ->and($run->modelHasVerifiedWrite('openai', 'gpt-full-same'))->toBeTrue();

    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-full-same')->firstOrFail();
    expect($row->pricing_verified_at)->not->toBeNull();
});

test('a dry-run tool writes nothing yet records a verification-grade would-create', function (): void {
    // FIX 3: the verify dry run threads dryRun into the tool so the writer is
    // called with dryRun: true — the outcome is computed for real (WouldCreate)
    // and, receipt-backed, still counts as verification-grade so the dry verify
    // genuinely resolves its target. No row is persisted.
    $run = new PriceVerificationRun;
    $run->requireReceipts();
    $run->recordFetch('https://developers.openai.com/api/docs/pricing');

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run)->withDryRun(true), [
        'provider' => 'openai',
        'model' => 'gpt-dry',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
    ]);

    expect($result['dry_run'])->toBeTrue()
        ->and($result['outcome'])->toBe('would_create')
        ->and($run->providerHasVerifiedWrite('openai'))->toBeTrue()
        ->and($run->modelHasVerifiedWrite('openai', 'gpt-dry'))->toBeTrue()
        ->and(AiModelPrice::count())->toBe(0);
});

test('a dry-run unchanged write computes the comparison without stamping verification', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-dry-same',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'pricing_verified_at' => null,
    ]);

    $run = new PriceVerificationRun;
    $run->requireReceipts();
    $run->recordFetch('https://developers.openai.com/api/docs/pricing');

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run)->withDryRun(true), [
        'provider' => 'openai',
        'model' => 'gpt-dry-same',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
    ]);

    expect($result['outcome'])->toBe('unchanged')
        ->and($run->modelHasVerifiedWrite('openai', 'gpt-dry-same'))->toBeTrue();

    // The comparison ran for real, but a dry run never stamps.
    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-dry-same')->firstOrFail();
    expect($row->pricing_verified_at)->toBeNull();
});

test('a rejected write never resolves its exact-model target in the ledger', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-anom-ledger',
        'input_per_mtok' => 1.0,
        'output_per_mtok' => 4.0,
    ]);

    $run = new PriceVerificationRun;

    // Provider-native path: the anomalous value is rejected by the guard, so
    // the exact-model target must stay unverified in the ledger.
    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-anom-ledger',
        'input_per_mtok' => 10.0,
        'output_per_mtok' => 40.0,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
    ]);

    expect($result['error'])->toBe('write_rejected')
        ->and($run->modelHasVerifiedWrite('openai', 'gpt-anom-ledger'))->toBeFalse();
});

test('an anthropic fetch receipt cannot authorize an openai write on the custom-fetch path', function (): void {
    // Cross-provider receipt forgery: the run genuinely fetched an Anthropic
    // page, but that receipt must never authorize a write attributed to OpenAI.
    $run = new PriceVerificationRun;
    $run->requireReceipts();
    $run->recordFetch('https://claude.com/pricing');

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'https://claude.com/pricing',
    ]);

    expect($result['error'])->toBe('unverified_source')
        ->and(AiModelPrice::count())->toBe(0);
});

test('an anthropic pricing page cannot authorize an openai write on the provider-native path', function (): void {
    // Provider-native path (no receipts): the host-to-provider binding alone
    // must still block an Anthropic page from stamping an OpenAI row.
    $run = new PriceVerificationRun;

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'https://claude.com/pricing',
    ]);

    expect($result['error'])->toBe('unverified_source')
        ->and(AiModelPrice::count())->toBe(0);
});

test('a matching provider and fetch receipt authorizes the write', function (): void {
    $run = new PriceVerificationRun;
    $run->requireReceipts();
    $run->recordFetch('https://developers.openai.com/api/docs/pricing');

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
    ]);

    expect($result['upserted'])->toBeTrue()
        ->and($run->providerHasVerifiedWrite('openai'))->toBeTrue();
});

test('a source_url host with no provider mapping is rejected', function (): void {
    // A host that resolves to no canonical provider can never back a write,
    // even on the provider-native path.
    $run = new PriceVerificationRun;

    $result = upsert(upsertTool(RefreshScope::all())->withRun($run), [
        'provider' => 'openai',
        'model' => 'gpt-x',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5.0,
        'source_url' => 'https://example.org/pricing',
    ]);

    expect($result['error'])->toBe('unverified_source')
        ->and(AiModelPrice::count())->toBe(0);
});
