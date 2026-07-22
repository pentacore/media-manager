<?php

declare(strict_types=1);

namespace App\Ai\Tools\PriceFetcher;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\PricingSource;
use App\Models\AiModelPrice;
use App\Services\AiUsage\Pricing\AiModelPriceWriter;
use App\Services\AiUsage\Pricing\Data\CandidatePriceField;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\WriteOutcome;
use App\Services\AiUsage\Pricing\PriceVerificationRun;
use App\Services\AiUsage\Pricing\PricingSourceHosts;
use App\Services\AiUsage\Pricing\RefreshScope;
use DateTimeImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * SafeWrite tool: upsert one row in ai_model_prices by the provider+model
 * unique key, used by PriceFetcherAgent after it has fetched a provider pricing
 * page and parsed the relevant rates.
 *
 * The tool never writes directly. It builds a {@see ModelPriceCandidate} and
 * defers to {@see AiModelPriceWriter}, which is the single place that enforces
 * canonical provider identity, missing-vs-explicit-zero merge semantics, lock
 * protection, decimal-safe comparison, relative-change anomaly detection, and
 * provenance stamping. Writes are additionally gated by a per-run
 * {@see RefreshScope} that defaults to deny-all, so an unscoped automatic run
 * fails closed instead of touching the catalog.
 *
 * Verification grade: only the receipt-gated custom {@see WebFetchTool} path
 * produces first-party VERIFIED writes (anomaly-guard bypass plus a
 * `pricing_verified_at` stamp), because only that path can prove the page was
 * genuinely fetched this run. The provider-native WebFetch path is a
 * best-effort refresh — writes land under the host-to-provider binding but are
 * never treated as verified.
 */
class UpsertModelPriceTool extends BaseTool
{
    /**
     * The rate columns accepted from the agent, in {@see AiModelPrice} column
     * order. A null (or absent) value is a missing rate the writer must leave
     * untouched; an explicit numeric value — including zero — is written.
     *
     * @var list<string>
     */
    private const array PRICE_COLUMNS = [
        'input_per_mtok',
        'output_per_mtok',
        'cache_read_per_mtok',
        'cache_write_per_mtok',
        'reasoning_per_mtok',
        'batch_input_per_mtok',
        'batch_output_per_mtok',
        'batch_cache_read_per_mtok',
        'batch_cache_write_per_mtok',
        'batch_reasoning_per_mtok',
    ];

    /**
     * Per-run write allowlist. Deny-all by default so an unscoped instance never
     * writes; PriceFetcherAgent narrows it with {@see withScope()} per run.
     */
    private RefreshScope $scope;

    /**
     * Per-run receipt / outcome ledger shared with {@see WebFetchTool}. Null
     * until {@see withRun()} binds one; when present the tool both requires a
     * verified source before writing and records every {@see WriteOutcome} for
     * the coordinator's ledger-driven fallback resolution.
     */
    private ?PriceVerificationRun $run = null;

    /**
     * Whether this run persists for real (false) or computes outcomes without
     * writing (true). Threaded from {@see PriceFetcherAgent} so a verify dry run
     * performs real fetches, parsing, and first-party comparison end to end
     * while touching no rows and stamping no verification.
     */
    private bool $dryRun = false;

    public function __construct(private readonly AiModelPriceWriter $writer)
    {
        $this->scope = RefreshScope::forProviders([]);
    }

    /**
     * Narrow the providers and models this instance may write for the current
     * run. Returns a clone so per-run scope never leaks between resolutions.
     */
    public function withScope(RefreshScope $scope): static
    {
        $clone = clone $this;
        $clone->scope = $scope;

        return $clone;
    }

    /**
     * Bind the per-run verification ledger. Returns a clone so per-run state
     * never leaks between resolutions (Octane / shared-instance safety).
     */
    public function withRun(PriceVerificationRun $run): static
    {
        $clone = clone $this;
        $clone->run = $run;

        return $clone;
    }

    /**
     * Set whether writes are dry-run (computed but not persisted). Returns a
     * clone so per-run state never leaks between resolutions.
     */
    public function withDryRun(bool $dryRun): static
    {
        $clone = clone $this;
        $clone->dryRun = $dryRun;

        return $clone;
    }

    public function description(): Stringable|string
    {
        return 'Upsert one AI model pricing row by provider + model. All rates are dollars per million tokens (per_mtok). Pass null for a tier you cannot read from the page (it leaves any existing value untouched); pass 0 only to record an explicit zero (e.g. cache_write_per_mtok for OpenAI). Returns the resulting row.';
    }

    public function risk(): Risk
    {
        return Risk::SafeWrite;
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $args = $request->toArray();

        $provider = trim((string) ($args['provider'] ?? ''));
        $model = trim((string) ($args['model'] ?? ''));

        if ($provider === '' || $model === '') {
            return [
                'error' => 'invalid_args',
                'message' => 'provider and model are required.',
            ];
        }

        $sourceUrl = $this->nullableString($args['source_url'] ?? null);
        $canonicalProvider = RefreshScope::canonicalProvider($provider);

        // Provenance gate: a first-party verified write must be backed by a real
        // fetch of an allowlisted page that belongs to the SAME provider as the
        // candidate. When a run ledger is bound (the agent path) the source_url
        // must be an allowlisted http(s) URL whose host maps to this candidate's
        // canonical provider, and on the custom-fetch path it must additionally
        // match a page this run actually fetched for that provider. This is what
        // makes `pricing_verified_at` trustworthy instead of a flag the model
        // could set from an invented — or another provider's — URL.
        if ($this->run !== null && ! $this->sourceIsVerified($sourceUrl, $canonicalProvider)) {
            return [
                'error' => 'unverified_source',
                'message' => sprintf(
                    'The write for %s/%s was refused: source_url must be the exact allowlisted pricing page you fetched with WebFetchTool. Fetch the page first, then upsert using the url it returned.',
                    $provider,
                    $model,
                ),
            ];
        }

        $candidate = new ModelPriceCandidate(
            provider: $provider,
            model: $model,
            fields: $this->priceFields($args),
            sourceUrl: $sourceUrl,
            sourceUpdatedAt: $this->validSourceDate($args['source_updated_at'] ?? null),
        );

        // Verification grade is earned only on the receipt-gated custom-fetch
        // path: the run must require receipts AND hold an exact provider-matching
        // receipt for this source_url. On the provider-native WebFetch path no
        // local receipt can exist, so the write proceeds as a best-effort refresh
        // (firstPartyVerified false): the anomaly guard still applies and
        // `pricing_verified_at` is never stamped.
        $firstPartyVerified = $this->run !== null
            && $this->run->requiresReceipts()
            && $canonicalProvider !== null
            && $this->run->hasReceiptForProvider($sourceUrl, $canonicalProvider);

        $outcome = $this->writer->write(
            $candidate,
            $this->scope,
            PricingSource::FirstParty,
            dryRun: $this->dryRun,
            firstPartyVerified: $firstPartyVerified,
        );

        // Verification grade mirrors the writer's own downgrade: a receipt-backed
        // write only resolves a target when it also re-read both primary rates.
        // A cache-only or all-null update is recorded for audit but never counts
        // as verification-grade, so it cannot resolve its target.
        $verificationGrade = $firstPartyVerified && $candidate->suppliesPrimaryRates();

        $this->run?->recordOutcome($canonicalProvider ?? $provider, $outcome, $model, verificationGrade: $verificationGrade);

        return $this->respond($outcome, $provider, $model);
    }

    /**
     * Whether the given source URL is acceptable provenance for a write of the
     * given candidate provider under the bound run.
     *
     * The URL must always be a plain allowlisted http(s) page whose host maps to
     * the SAME canonical provider as the candidate — an Anthropic pricing page
     * can never authorize an OpenAI write. On the custom {@see WebFetchTool}
     * path ({@see PriceVerificationRun::requiresReceipts()}) it must additionally
     * match a page this run genuinely fetched for that provider; only such
     * receipt-backed writes are verification-grade. On the provider-native
     * WebFetch path the model provider performs the fetch and no local receipt
     * exists, so the host-to-provider binding plus scheme validation admit the
     * write — but only as a best-effort refresh, never as first-party verified.
     */
    private function sourceIsVerified(?string $sourceUrl, ?string $candidateProvider): bool
    {
        if (! WebFetchTool::allowsUrl($sourceUrl)) {
            return false;
        }

        // The source page's host must belong to the candidate's provider. An
        // unmapped host, or a mismatch (openai candidate, anthropic page),
        // fails closed even before receipts are considered.
        $sourceProvider = PricingSourceHosts::providerForUrl($sourceUrl);

        if ($sourceProvider === null || $sourceProvider !== $candidateProvider) {
            return false;
        }

        if ($this->run !== null && $this->run->requiresReceipts()) {
            return $this->run->hasReceiptForProvider($sourceUrl, $sourceProvider);
        }

        return true;
    }

    /**
     * Build the candidate field map, preserving the missing-vs-explicit-zero
     * distinction the writer relies on. Non-numeric input is treated as missing.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, CandidatePriceField>
     */
    private function priceFields(array $args): array
    {
        $fields = [];

        foreach (self::PRICE_COLUMNS as $column) {
            $value = $args[$column] ?? null;

            $fields[$column] = is_numeric($value)
                ? CandidatePriceField::of(number_format((float) $value, 4, '.', ''))
                : CandidatePriceField::missing();
        }

        return $fields;
    }

    /**
     * Coerce a schema value to a trimmed non-empty string or null.
     */
    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Accept only a real calendar date in the schema's exact Y-m-d format.
     * Malformed or nonexistent dates are source metadata gaps, not write errors.
     */
    private function validSourceDate(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null || str_starts_with($value, '0000-')) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * Translate a writer outcome into the tool's JSON response envelope.
     *
     * @return array<string, mixed>
     */
    private function respond(WriteOutcome $outcome, string $provider, string $model): array
    {
        return match ($outcome) {
            WriteOutcome::Created,
            WriteOutcome::Updated,
            WriteOutcome::Unchanged => $this->success($outcome, $provider, $model),
            WriteOutcome::WouldCreate,
            WriteOutcome::WouldUpdate => [
                'upserted' => false,
                'dry_run' => true,
                'outcome' => $outcome->value,
                'provider' => $provider,
                'model' => $model,
            ],
            WriteOutcome::Locked => [
                'error' => 'price_locked',
                'message' => sprintf('%s/%s is locked for manual editing; the automatic write was skipped. Report it in your summary and move on.', $provider, $model),
            ],
            default => [
                'error' => 'write_rejected',
                'message' => sprintf('The write for %s/%s was rejected: it is out of this run\'s scope, an unsupported provider, missing a required input/output rate, an out-of-range value, or an implausible change from the stored price. Re-read the pricing page instead of retrying with the same values.', $provider, $model),
            ],
        };
    }

    /**
     * Load the persisted row (by canonical provider) and shape the success
     * response. Falls back to a rejection envelope if the row cannot be found.
     *
     * @return array<string, mixed>
     */
    private function success(WriteOutcome $outcome, string $provider, string $model): array
    {
        $canonicalProvider = RefreshScope::canonicalProvider($provider) ?? $provider;

        $row = AiModelPrice::query()
            ->where('provider', $canonicalProvider)
            ->where('model', $model)
            ->first();

        if ($row === null) {
            return [
                'error' => 'write_rejected',
                'message' => sprintf('The write for %s/%s did not persist a row.', $provider, $model),
            ];
        }

        return [
            'upserted' => true,
            'outcome' => $outcome->value,
            'id' => $row->id,
            'provider' => $row->provider,
            'model' => $row->model,
            'input_per_mtok' => (float) $row->input_per_mtok,
            'output_per_mtok' => (float) $row->output_per_mtok,
        ];
    }

    /**
     * Every property is marked required because OpenAI's strict tool schema
     * demands that `required` enumerate the full property list. Rate tiers are
     * additionally nullable: pass null for a tier you cannot read (leaves any
     * existing value untouched), or 0 to record an explicit zero. The agent
     * prompt calls this out.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $schema->string()
                ->description('Lowercase provider key, e.g. "openai", "anthropic", "google", "deepseek", "xai", "mistral", "groq", "cohere". Input "google" is canonicalized and persisted as "gemini".')
                ->required(),
            'model' => $schema->string()
                ->description('Model identifier exactly as the provider lists it (e.g. "gpt-5-mini", "claude-sonnet-4-6", "gemini-2.5-pro", "deepseek-chat").')
                ->required(),
            'input_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 input tokens. Required to create a new row; null leaves an existing value untouched.')
                ->required()
                ->nullable(),
            'output_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 output tokens. Required to create a new row; null leaves an existing value untouched.')
                ->required()
                ->nullable(),
            'cache_read_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 cached-input tokens read. 0 for an explicit zero; null if the page does not list it.')
                ->required()
                ->nullable(),
            'cache_write_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 cache-write tokens (Anthropic prompt-caching writes). 0 for an explicit zero; null if N/A.')
                ->required()
                ->nullable(),
            'reasoning_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 reasoning tokens (o-series, Gemini thinking). 0 for an explicit zero; null if the model does not surface a reasoning rate.')
                ->required()
                ->nullable(),
            'batch_input_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 batch-input tokens. 0 for an explicit zero; null if no batch tier exists.')
                ->required()
                ->nullable(),
            'batch_output_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 batch-output tokens. 0 for an explicit zero; null if N/A.')
                ->required()
                ->nullable(),
            'batch_cache_read_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 batch cache-read tokens. 0 for an explicit zero; null if N/A.')
                ->required()
                ->nullable(),
            'batch_cache_write_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 batch cache-write tokens. 0 for an explicit zero; null if N/A.')
                ->required()
                ->nullable(),
            'batch_reasoning_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 batch reasoning tokens. 0 for an explicit zero; null if N/A.')
                ->required()
                ->nullable(),
            'source_url' => $schema->string()
                ->description('The exact provider pricing page URL these rates were read from.')
                ->required(),
            'source_updated_at' => $schema->string()
                ->description('The date the pricing page states it was last updated (YYYY-MM-DD), or null if the page does not state one.')
                ->required()
                ->nullable(),
        ];
    }
}
