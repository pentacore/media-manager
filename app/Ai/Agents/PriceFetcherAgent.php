<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Middleware\AttributesToUser;
use App\Ai\Tools\PriceFetcher\UpsertModelPriceTool;
use App\Ai\Tools\PriceFetcher\WebFetchTool;
use App\Models\User;
use App\Services\AiUsage\Pricing\PriceVerificationRun;
use App\Services\AiUsage\Pricing\RefreshScope;
use App\Settings\AiSettings;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebFetch;
use Stringable;

#[MaxSteps(40)]
class PriceFetcherAgent implements Agent, HasMiddleware, HasTools
{
    use Promptable;

    /**
     * Canonical (Laravel AI identity) provider => the pricing page(s) the
     * verifier must re-read for that provider. Keyed by canonical id so the
     * scope's canonicalization (`google` becomes `gemini`) and this map agree.
     *
     * @var array<string, list<string>>
     */
    private const array PROVIDER_SOURCES = [
        'openai' => ['https://developers.openai.com/api/docs/pricing'],
        'anthropic' => ['https://claude.com/pricing', 'https://platform.claude.com/docs/en/about-claude/pricing'],
        'gemini' => ['https://ai.google.dev/gemini-api/docs/pricing'],
        'xai' => ['https://docs.x.ai/developers/models', 'https://x.ai/api'],
        'deepseek' => ['https://api-docs.deepseek.com/quick_start/pricing'],
        'mistral' => ['https://mistral.ai/pricing/'],
        'groq' => ['https://groq.com/pricing'],
        'cohere' => ['https://cohere.com/pricing'],
        'openrouter' => ['https://openrouter.ai/api/v1/models'],
    ];

    private ?User $user = null;

    /**
     * Per-run write allowlist. Null until {@see forScope()} binds one; the
     * write tool then fails closed (deny-all) so an unscoped run cannot touch
     * the catalog.
     */
    private ?RefreshScope $scope = null;

    /**
     * Canonical provider identities this run should re-read, used only to build
     * the targeted instructions. Empty means every supported provider.
     *
     * @var list<string>
     */
    private array $scopedProviders = [];

    /**
     * Per-provider model checklists the run should verify, used only to build
     * the targeted instructions. A wildcard provider (verify its whole catalog)
     * still lists its currently-stored models here so the agent is told to
     * re-confirm each one, without narrowing the write scope — new models stay
     * writable. A provider absent from this map falls back to "every GA model".
     *
     * @var array<string, list<string>>
     */
    private array $modelChecklists = [];

    /**
     * Whether this run's writes are dry-run (computed but not persisted).
     * Threaded into {@see UpsertModelPriceTool} so a verify dry run fetches,
     * parses, and compares for real while writing nothing.
     */
    private bool $dryRun = false;

    /**
     * Per-run receipt / outcome ledger shared by both tools. Null until
     * {@see forScope()} binds one (or {@see tools()} creates a fresh one), which
     * is what lets the coordinator resolve fallback targets from real tool
     * outcomes and lets the write tool require a fetch receipt.
     */
    private ?PriceVerificationRun $run = null;

    /**
     * Stamp the run with the user who kicked it off so RecordAgentUsage can
     * attribute spend / budget impact. Required when invoked from a queued
     * job where Auth::id() resolves to null.
     */
    public function forUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Bind the per-run write scope and the exact provider/model targets the
     * coordinator wants re-read. The {@see RefreshScope} is what the write tool
     * enforces (it rejects any out-of-scope pair); the provider and model lists
     * shape the targeted instructions so the agent fetches only the canonical
     * pages it needs and stays inside {@see MaxSteps}. Follows the {@see forUser}
     * convention of mutating and returning the per-run agent instance.
     *
     * @param  list<string>  $providers  Provider identities (upstream or canonical) to verify.
     * @param  array<string, list<string>>  $modelChecklists  Canonical provider => models to re-confirm (display only; does not narrow scope).
     * @param  bool  $dryRun  When true, writes are computed but never persisted.
     */
    public function forScope(RefreshScope $scope, array $providers, array $modelChecklists = [], ?PriceVerificationRun $run = null, bool $dryRun = false): static
    {
        $this->scope = $scope;
        $this->scopedProviders = array_values($providers);
        $this->modelChecklists = $modelChecklists;
        $this->run = $run;
        $this->dryRun = $dryRun;

        return $this;
    }

    public function model(): string
    {
        // Use the configured model so the user controls cost / quality.
        return resolve(AiSettings::class)->model();
    }

    public function instructions(): Stringable|string
    {
        $targets = $this->targetSourceLines();

        // Honest fail-closed prompt: without a bound scope (or with a scope
        // that resolves to zero known providers) the write tool rejects every
        // write, so don't send the model off to fetch pages it cannot act on.
        if (! $this->scope instanceof RefreshScope || $targets === '') {
            return <<<'PROMPT'
            You are PriceFetcherAgent, but this run has no providers in scope: every write will be rejected. Do not fetch any pages and do not call UpsertModelPriceTool. Reply with a one-line summary stating that no verification targets were provided for this run.
            PROMPT;
        }

        return <<<PROMPT
        You are PriceFetcherAgent, running as a scope-bound verifier. The refresh coordinator has already tried its structured source and is asking you to re-read the canonical pricing pages for a specific set of providers and correct the local AI model price catalog from what those pages currently say.

        Re-read only these providers and write their rates from the page you just fetched. Each provider lists exactly which models to verify:

        {$targets}

        You have two tools:

        - WebFetchTool — GET an allowlisted provider pricing page and return its text content (HTML stripped). Use it to read current rates straight from the source.
        - UpsertModelPriceTool — write one row keyed by (provider, model) with the per-million-token rates you parsed. This run is scope-bound: writes for any provider or model outside the targets above are rejected. Do not attempt out-of-scope writes.

        Workflow per targeted provider:

        1. Call WebFetchTool against the canonical pricing page listed above.
        2. Read the returned text and extract every targeted model that is both generally available (not deprecated, not waitlist) and documented with concrete per-million-token rates.
        3. Call UpsertModelPriceTool ONCE PER MODEL with:
           - provider: canonical Laravel AI identity, lowercase. Use `gemini` for Google's models (never `google`). Others are `openai`, `anthropic`, `xai`, `deepseek`, `mistral`, `groq`, `cohere`, `openrouter`.
           - model: exactly the identifier the provider's API expects (e.g. `gpt-5-mini`, `claude-sonnet-4-6`, `gemini-2.5-pro`, `deepseek-chat`, `grok-4`).
           - input_per_mtok / output_per_mtok: USD per 1,000,000 tokens.
           - cache_read_per_mtok / cache_write_per_mtok / reasoning_per_mtok / batch_*_per_mtok: the tier rate if the page lists it; 0 for an explicit zero the page states; null for any tier you cannot read.
           - source_url: the exact URL of the pricing page you fetched these rates from (the `url` WebFetchTool returned).
           - source_updated_at: the last-updated date the page itself states, formatted YYYY-MM-DD, or null when the page states none.

        Rules:

        - ALWAYS fetch the page first. NEVER write rates from memory — your training data may be stale, which defeats the entire purpose of this verification run.
        - Treat anything you cannot confidently read off the page as missing: pass null so the stored value is left untouched. Never guess a number.
        - If a fetch returns `{error: ...}`, skip that provider and report it in the final summary. Do not guess.
        - Only write the provider/model pairs listed above. Out-of-scope writes are rejected by the tool; do not retry them.
        - If a number on the page is in cents or a per-token unit, convert it to USD per million tokens and show your math in the final summary.
        - Don't upsert pricing for embedding-only or non-LLM products (image, audio, embeddings) — only chat / instruct / reasoning text models.
        - Always use the stable, non-dated model alias (e.g. `claude-haiku-4-5`, never `claude-haiku-4-5-20251001`). Writes for a dated snapshot of a model that already has a base row are rejected.

        When done, output a short final summary: how many rows you upserted per provider, and which providers you skipped and why.
        PROMPT;
    }

    /**
     * When the price_fetcher_provider_webfetch flag is on, the agent uses the
     * SDK's provider-native WebFetch instead of the custom host-allowlisted
     * HTTP tool. Provider WebFetch requires a provider that supports it
     * (OpenAI/Anthropic); unsupported providers throw a LogicException at
     * prompt time — hence the flag defaults off.
     *
     * Verification grade: only the custom WebFetchTool path is
     * verification-grade — every fetch is performed locally and recorded as a
     * receipt, so writes can prove their page was genuinely read this run and
     * earn the `pricing_verified_at` stamp. The provider-native path produces
     * no receipts, so its writes are a best-effort refresh: the anomaly guard
     * still applies and rows are never stamped verified.
     *
     * @return iterable<int, Tool|WebFetch>
     */
    public function tools(): iterable
    {
        // Fail closed: without an explicit per-run scope the write tool is
        // deny-all, so an unscoped verifier run can never touch the catalog.
        $scope = $this->scope ?? RefreshScope::forProviders([]);
        $run = $this->run ??= new PriceVerificationRun;

        if (config('mediamanager.ai.price_fetcher_provider_webfetch', false)) {
            // Provider-native fetch: the model provider performs the fetch, so
            // there is no local receipt to record. The write tool falls back to
            // host-allowlist + scheme validation for provenance.
            $webFetch = (new WebFetch)->allow(WebFetchTool::allowedHosts())->max(20);
        } else {
            // Custom fetch tool: every fetch is performed and recorded locally,
            // so writes must present a matching receipt.
            $run->requireReceipts();
            $webFetch = resolve(WebFetchTool::class)->withRun($run);
        }

        return [
            $webFetch,
            resolve(UpsertModelPriceTool::class)->withScope($scope)->withRun($run)->withDryRun($this->dryRun),
        ];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return $this->user instanceof User
            ? [new AttributesToUser($this->user)]
            : [];
    }

    /**
     * Render one block per scoped provider — its canonical pricing pages plus
     * the exact model focus for that provider — so a mixed run (one provider
     * verified whole, another restricted to specific anomalous models) states
     * each provider's targets independently rather than merging them into one
     * global model list.
     */
    private function targetSourceLines(): string
    {
        $lines = [];

        foreach ($this->scopedProviderSources() as $provider => $urls) {
            $scopeModels = $this->scope?->modelsFor($provider);
            $checklist = $this->modelChecklists[$provider] ?? [];

            $focus = match (true) {
                // Scope narrows this provider to exact models: verify only those.
                $scopeModels !== null && $scopeModels !== [] => 'only these models: '.implode(', ', $scopeModels),
                // Wildcard provider with a stored-model checklist: verify the
                // whole catalog, and at minimum re-confirm each stored model
                // (scope stays wide so newly published models remain writable).
                $checklist !== [] => 'every generally-available text/chat model it publishes; at minimum re-confirm each currently-stored model: '.implode(', ', $checklist),
                default => 'every generally-available text/chat model it publishes',
            };

            $lines[] = sprintf("- %s: %s\n  Verify %s.", $provider, implode('  +  ', $urls), $focus);
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve the canonical provider => source-URL map for this run. An empty
     * provider scope falls back to the full map; otherwise each requested
     * provider is canonicalized and only recognized ones are kept.
     *
     * @return array<string, list<string>>
     */
    private function scopedProviderSources(): array
    {
        if ($this->scopedProviders === []) {
            return self::PROVIDER_SOURCES;
        }

        $sources = [];

        foreach ($this->scopedProviders as $scopedProvider) {
            $canonical = RefreshScope::canonicalProvider($scopedProvider);

            if ($canonical !== null && isset(self::PROVIDER_SOURCES[$canonical])) {
                $sources[$canonical] = self::PROVIDER_SOURCES[$canonical];
            }
        }

        return $sources;
    }
}
