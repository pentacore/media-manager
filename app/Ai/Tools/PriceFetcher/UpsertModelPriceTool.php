<?php

declare(strict_types=1);

namespace App\Ai\Tools\PriceFetcher;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Models\AiModelPrice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * SafeWrite tool: upsert one row in ai_model_prices using the
 * provider+model unique key. Used by PriceFetcherAgent after it has
 * fetched a provider pricing page and parsed the relevant rates.
 */
class UpsertModelPriceTool extends BaseTool
{
    /**
     * USD per MTok above which a rate is assumed to be a parse error or a
     * poisoned page rather than a real price (the priciest real tiers are
     * two orders of magnitude below this).
     */
    public const float MAX_RATE_USD_PER_MTOK = 1000.0;

    /**
     * Largest believable single-refresh price *drop* factor for the primary
     * rates. Bigger drops (including to zero) would blind AiBudgetGuard —
     * cost snapshots taken at usage time would record ~$0 spend, so the
     * monthly hard cap never trips.
     */
    private const float MAX_DROP_FACTOR = 100.0;

    public function description(): Stringable|string
    {
        return 'Upsert one AI model pricing row by provider + model. All rates are dollars per million tokens (per_mtok). Pass 0 for tiers a model does not support (e.g. cache_write_per_mtok for OpenAI). Returns the resulting row.';
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

        $provider = (string) ($args['provider'] ?? '');
        $model = (string) ($args['model'] ?? '');

        if ($provider === '' || $model === '') {
            return [
                'error' => 'invalid_args',
                'message' => 'provider and model are required.',
            ];
        }

        $payload = [
            'input_per_mtok' => (float) ($args['input_per_mtok'] ?? 0),
            'output_per_mtok' => (float) ($args['output_per_mtok'] ?? 0),
            'cache_read_per_mtok' => (float) ($args['cache_read_per_mtok'] ?? 0),
            'cache_write_per_mtok' => (float) ($args['cache_write_per_mtok'] ?? 0),
            'reasoning_per_mtok' => (float) ($args['reasoning_per_mtok'] ?? 0),
            'batch_input_per_mtok' => (float) ($args['batch_input_per_mtok'] ?? 0),
            'batch_output_per_mtok' => (float) ($args['batch_output_per_mtok'] ?? 0),
            'batch_cache_read_per_mtok' => (float) ($args['batch_cache_read_per_mtok'] ?? 0),
            'batch_cache_write_per_mtok' => (float) ($args['batch_cache_write_per_mtok'] ?? 0),
            'batch_reasoning_per_mtok' => (float) ($args['batch_reasoning_per_mtok'] ?? 0),
        ];

        // The rates come from an LLM reading third-party web content, and
        // AiBudgetGuard's spend accounting is only as honest as this table —
        // bound-check every write instead of trusting the parse.
        foreach ($payload as $field => $rate) {
            if ($rate < 0 || $rate > self::MAX_RATE_USD_PER_MTOK) {
                return [
                    'error' => 'rate_out_of_bounds',
                    'field' => $field,
                    'message' => sprintf(
                        '%s must be between 0 and %.0f USD per MTok; got %s. Re-read the pricing page instead of retrying with the same value.',
                        $field,
                        self::MAX_RATE_USD_PER_MTOK,
                        $rate,
                    ),
                ];
            }
        }

        $existing = AiModelPrice::query()
            ->where('provider', $provider)
            ->where('model', $model)
            ->first();

        if ($existing !== null) {
            foreach (['input_per_mtok', 'output_per_mtok'] as $field) {
                $current = (float) $existing->{$field};
                $proposed = $payload[$field];

                if ($current > 0 && $proposed < $current / self::MAX_DROP_FACTOR) {
                    return [
                        'error' => 'implausible_price_drop',
                        'field' => $field,
                        'current' => $current,
                        'proposed' => $proposed,
                        'message' => sprintf(
                            '%s for %s/%s would drop from %.4f to %.4f — more than %.0fx. Zeroed or near-zero rates would disable budget enforcement, so this must be changed by an admin, not the agent.',
                            $field,
                            $provider,
                            $model,
                            $current,
                            $proposed,
                            self::MAX_DROP_FACTOR,
                        ),
                    ];
                }
            }
        }

        $row = AiModelPrice::updateOrCreate(
            ['provider' => $provider, 'model' => $model],
            $payload,
        );

        return [
            'upserted' => true,
            'id' => $row->id,
            'provider' => $row->provider,
            'model' => $row->model,
            'input_per_mtok' => (float) $row->input_per_mtok,
            'output_per_mtok' => (float) $row->output_per_mtok,
        ];
    }

    /**
     * Every property is marked required because OpenAI's strict tool
     * schema demands that `required` enumerate the full property list.
     * Pass 0 for tiers that don't apply (e.g. cache_write_per_mtok for
     * OpenAI, where they only bill cache reads); the agent prompt
     * already calls this out.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $schema->string()
                ->description('Lowercase provider key, e.g. "openai", "anthropic", "google", "deepseek", "xai", "mistral", "groq", "cohere".')
                ->required(),
            'model' => $schema->string()
                ->description('Model identifier exactly as the provider lists it (e.g. "gpt-5-mini", "claude-sonnet-4-6", "gemini-2.5-pro", "deepseek-chat").')
                ->required(),
            'input_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 input tokens.')
                ->required(),
            'output_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 output tokens.')
                ->required(),
            'cache_read_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 cached-input tokens read. 0 if the model does not bill cache reads separately.')
                ->required(),
            'cache_write_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 cache-write tokens (Anthropic prompt-caching writes). 0 if N/A.')
                ->required(),
            'reasoning_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 reasoning tokens (o-series, Gemini thinking). 0 if the model does not surface a reasoning rate.')
                ->required(),
            'batch_input_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 batch-input tokens. 0 if no batch tier exists.')
                ->required(),
            'batch_output_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 batch-output tokens. 0 if N/A.')
                ->required(),
            'batch_cache_read_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 batch cache-read tokens. 0 if N/A.')
                ->required(),
            'batch_cache_write_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 batch cache-write tokens. 0 if N/A.')
                ->required(),
            'batch_reasoning_per_mtok' => $schema->number()
                ->description('USD per 1,000,000 batch reasoning tokens. 0 if N/A.')
                ->required(),
        ];
    }
}
