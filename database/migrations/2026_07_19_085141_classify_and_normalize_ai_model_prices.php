<?php

declare(strict_types=1);

use App\Enums\PricingSource;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Price columns compared when deciding whether a stored row is an
     * untouched seed row or a manually diverged one.
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
     * Cap on the number of unresolved collision warnings emitted so a badly
     * duplicated table cannot flood the log.
     */
    private const int MAX_COLLISION_WARNINGS = 25;

    /**
     * Classify existing pricing rows against the frozen pre-feature seed and
     * canonicalize legacy `google` provider identities onto `gemini`.
     *
     * Rows whose identity and prices exactly match the frozen seed are stamped
     * as unlocked seed data; anything else (edited seeds, unknown rows) is
     * locked and marked manual so later automatic syncs leave it alone.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $frozenSeed = $this->frozenSeedIndex();

            $collisionLockedIds = $this->canonicalizeGoogleProvider();

            $rows = DB::table('ai_model_prices')
                ->where(function (Builder $query): void {
                    $query->whereNull('pricing_source')
                        ->orWhere('pricing_source', PricingSource::Legacy->value);
                })
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                if (in_array((int) $row->id, $collisionLockedIds, true)) {
                    // A differing google/gemini collision already force-locked
                    // this row; do not reclassify it back to seed.
                    continue;
                }

                $key = $row->provider.'|'.$row->model;
                $isSeed = isset($frozenSeed[$key])
                    && $this->matchesFrozenPrices($row, $frozenSeed[$key]);

                DB::table('ai_model_prices')->where('id', $row->id)->update([
                    'pricing_source' => $isSeed
                        ? PricingSource::Seed->value
                        : PricingSource::Manual->value,
                    'is_price_locked' => ! $isSeed,
                ]);
            }
        });
    }

    /**
     * The classification, provider renames, and duplicate removals performed by
     * up() cannot be reversed without risking loss of subsequent manual/source
     * changes, so rollback is intentionally non-destructive.
     */
    public function down(): void
    {
        // Intentionally no-op: never blanket-clear provenance or unlock prices.
    }

    /**
     * Rename legacy `google` rows onto the canonical `gemini` provider,
     * resolving unique-index collisions before any classification runs.
     *
     * @return list<int> IDs of gemini rows force-locked by a differing collision
     */
    private function canonicalizeGoogleProvider(): array
    {
        $lockedIds = [];
        $warnings = 0;

        $googleRows = DB::table('ai_model_prices')
            ->where('provider', 'google')
            ->where(function (Builder $query): void {
                $query->whereNull('pricing_source')
                    ->orWhere('pricing_source', PricingSource::Legacy->value);
            })
            ->orderBy('id')
            ->get();

        foreach ($googleRows as $googleRow) {
            $geminiRow = DB::table('ai_model_prices')
                ->where('provider', 'gemini')
                ->where('model', $googleRow->model)
                ->first();

            if ($geminiRow === null) {
                DB::table('ai_model_prices')
                    ->where('id', $googleRow->id)
                    ->update(['provider' => 'gemini']);

                continue;
            }

            if ($this->pricesEqual($googleRow, $geminiRow)
                && $this->mergeEqualCollision($googleRow, $geminiRow)) {
                // Exact price duplicate with no conflicting local policy; the
                // google row's free-pool assignment and rate limits were folded
                // onto the gemini row before it was dropped.
                continue;
            }

            // Conflicting values, or equal prices with conflicting local policy
            // (differing free pools or rate limits on both rows): keep both
            // rows. On the initial rollout the canonical legacy gemini row is
            // locked for manual review; on a later reapply, never overwrite
            // provenance already assigned by a post-deploy source.
            if ($geminiRow->pricing_source === null
                || $geminiRow->pricing_source === PricingSource::Legacy->value) {
                DB::table('ai_model_prices')->where('id', $geminiRow->id)->update([
                    'pricing_source' => PricingSource::Manual->value,
                    'is_price_locked' => true,
                ]);

                $lockedIds[] = (int) $geminiRow->id;
            }

            if ($warnings < self::MAX_COLLISION_WARNINGS) {
                Log::warning('Unresolved google/gemini price collision during classification migration.', [
                    'model' => $googleRow->model,
                    'google_row_id' => (int) $googleRow->id,
                    'gemini_row_id' => (int) $geminiRow->id,
                ]);

                $warnings++;
            }
        }

        return $lockedIds;
    }

    /**
     * Fold an equal-priced legacy google row onto its canonical gemini twin and
     * delete it, preserving any local policy the google row carried.
     *
     * Before deleting, the google row's free-usage pool is transferred when the
     * gemini row has none, and its rate limits are re-parented onto the gemini
     * row when the gemini row has none of its own (a plain delete would
     * cascade them away).
     *
     * Returns false without touching either row when both carry conflicting
     * policy — differing free pools, or rate limits on both rows — so the caller
     * treats the pair as an unresolved collision and preserves both.
     */
    private function mergeEqualCollision(object $googleRow, object $geminiRow): bool
    {
        $googlePoolId = $googleRow->free_usage_pool_id === null
            ? null
            : (int) $googleRow->free_usage_pool_id;
        $geminiPoolId = $geminiRow->free_usage_pool_id === null
            ? null
            : (int) $geminiRow->free_usage_pool_id;

        $googleHasRateLimits = DB::table('ai_model_rate_limits')
            ->where('ai_model_price_id', $googleRow->id)
            ->exists();
        $geminiHasRateLimits = DB::table('ai_model_rate_limits')
            ->where('ai_model_price_id', $geminiRow->id)
            ->exists();

        $poolsConflict = $googlePoolId !== null
            && $geminiPoolId !== null
            && $googlePoolId !== $geminiPoolId;

        if ($poolsConflict || ($googleHasRateLimits && $geminiHasRateLimits)) {
            return false;
        }

        if ($geminiPoolId === null && $googlePoolId !== null) {
            DB::table('ai_model_prices')->where('id', $geminiRow->id)->update([
                'free_usage_pool_id' => $googlePoolId,
            ]);
        }

        if ($googleHasRateLimits && ! $geminiHasRateLimits) {
            DB::table('ai_model_rate_limits')
                ->where('ai_model_price_id', $googleRow->id)
                ->update(['ai_model_price_id' => $geminiRow->id]);
        }

        DB::table('ai_model_prices')->where('id', $googleRow->id)->delete();

        return true;
    }

    /**
     * Whether a stored row's prices match a frozen seed price map.
     *
     * @param  array<string, string|null>  $frozen
     */
    private function matchesFrozenPrices(object $row, array $frozen): bool
    {
        return array_all(self::PRICE_COLUMNS, fn (string $column): bool => $this->normalize($row->{$column}) === $frozen[$column]);
    }

    /**
     * Whether two stored rows carry identical prices across all rate columns.
     */
    private function pricesEqual(object $left, object $right): bool
    {
        return array_all(self::PRICE_COLUMNS, fn (string $column): bool => $this->normalize($left->{$column}) === $this->normalize($right->{$column}));
    }

    /**
     * Normalize a decimal value to a fixed four-place string, preserving null,
     * so numerically equal values compare equal regardless of representation.
     */
    private function normalize(string|int|float|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }

    /**
     * The frozen pre-feature seed, keyed by "provider|model", with every price
     * column normalized to a four-place string. Batch columns are reconstructed
     * with the original 50%-of-synchronous formula the old seeder applied, so
     * rows written by that seeder are recognized as untouched seed data.
     *
     * This is an intentional copy frozen in the migration; it must never call
     * the mutable AiModelPriceSeeder.
     *
     * @return array<string, array<string, string|null>>
     */
    private function frozenSeedIndex(): array
    {
        $batchSupported = ['openai', 'anthropic', 'gemini', 'mistral'];
        $index = [];

        foreach ($this->frozenSeedRows() as $row) {
            $normalized = [
                'input_per_mtok' => $this->normalize($row['input_per_mtok']),
                'output_per_mtok' => $this->normalize($row['output_per_mtok']),
                'cache_read_per_mtok' => $this->normalize($row['cache_read_per_mtok']),
                'cache_write_per_mtok' => $this->normalize($row['cache_write_per_mtok']),
                'reasoning_per_mtok' => $this->normalize($row['reasoning_per_mtok']),
                'batch_input_per_mtok' => null,
                'batch_output_per_mtok' => null,
                'batch_cache_read_per_mtok' => null,
                'batch_cache_write_per_mtok' => null,
                'batch_reasoning_per_mtok' => null,
            ];

            if (in_array($row['provider'], $batchSupported, true)) {
                $normalized['batch_input_per_mtok'] = $this->normalize(round($row['input_per_mtok'] * 0.5, 4));
                $normalized['batch_output_per_mtok'] = $this->normalize(round($row['output_per_mtok'] * 0.5, 4));
                $normalized['batch_cache_read_per_mtok'] = $this->normalize(round($row['cache_read_per_mtok'] * 0.5, 4));
                $normalized['batch_cache_write_per_mtok'] = $this->normalize(round($row['cache_write_per_mtok'] * 0.5, 4));
                $normalized['batch_reasoning_per_mtok'] = $this->normalize(round($row['reasoning_per_mtok'] * 0.5, 4));
            }

            $index[$row['provider'].'|'.$row['model']] = $normalized;
        }

        return $index;
    }

    /**
     * Frozen copy of the pre-feature seed base rates (synchronous only).
     *
     * @return list<array{provider: string, model: string, input_per_mtok: float, output_per_mtok: float, cache_read_per_mtok: float, cache_write_per_mtok: float, reasoning_per_mtok: float}>
     */
    private function frozenSeedRows(): array
    {
        return [
            ['provider' => 'openai', 'model' => 'gpt-5.5', 'input_per_mtok' => 5.00, 'output_per_mtok' => 30.00, 'cache_read_per_mtok' => 0.50, 'cache_write_per_mtok' => 5.00, 'reasoning_per_mtok' => 30.00],
            ['provider' => 'openai', 'model' => 'gpt-5.5-pro', 'input_per_mtok' => 30.00, 'output_per_mtok' => 180.00, 'cache_read_per_mtok' => 3.00, 'cache_write_per_mtok' => 30.00, 'reasoning_per_mtok' => 180.00],
            ['provider' => 'openai', 'model' => 'gpt-5.4', 'input_per_mtok' => 2.50, 'output_per_mtok' => 15.00, 'cache_read_per_mtok' => 0.25, 'cache_write_per_mtok' => 2.50, 'reasoning_per_mtok' => 15.00],
            ['provider' => 'openai', 'model' => 'gpt-5.4-mini', 'input_per_mtok' => 0.75, 'output_per_mtok' => 4.50, 'cache_read_per_mtok' => 0.075, 'cache_write_per_mtok' => 0.75, 'reasoning_per_mtok' => 4.50],
            ['provider' => 'openai', 'model' => 'gpt-5.4-nano', 'input_per_mtok' => 0.20, 'output_per_mtok' => 1.25, 'cache_read_per_mtok' => 0.02, 'cache_write_per_mtok' => 0.20, 'reasoning_per_mtok' => 1.25],
            ['provider' => 'openai', 'model' => 'gpt-5.3-codex', 'input_per_mtok' => 1.75, 'output_per_mtok' => 14.00, 'cache_read_per_mtok' => 0.175, 'cache_write_per_mtok' => 1.75, 'reasoning_per_mtok' => 14.00],
            ['provider' => 'openai', 'model' => 'gpt-5.1', 'input_per_mtok' => 1.25, 'output_per_mtok' => 10.00, 'cache_read_per_mtok' => 0.125, 'cache_write_per_mtok' => 1.25, 'reasoning_per_mtok' => 10.00],
            ['provider' => 'openai', 'model' => 'gpt-realtime-1.5', 'input_per_mtok' => 4.00, 'output_per_mtok' => 16.00, 'cache_read_per_mtok' => 0.40, 'cache_write_per_mtok' => 4.00, 'reasoning_per_mtok' => 0],

            ['provider' => 'anthropic', 'model' => 'claude-opus-4-7', 'input_per_mtok' => 5.00, 'output_per_mtok' => 25.00, 'cache_read_per_mtok' => 0.50, 'cache_write_per_mtok' => 6.25, 'reasoning_per_mtok' => 0],
            ['provider' => 'anthropic', 'model' => 'claude-opus-4-6', 'input_per_mtok' => 5.00, 'output_per_mtok' => 25.00, 'cache_read_per_mtok' => 0.50, 'cache_write_per_mtok' => 6.25, 'reasoning_per_mtok' => 0],
            ['provider' => 'anthropic', 'model' => 'claude-opus-4-5', 'input_per_mtok' => 5.00, 'output_per_mtok' => 25.00, 'cache_read_per_mtok' => 0.50, 'cache_write_per_mtok' => 6.25, 'reasoning_per_mtok' => 0],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'input_per_mtok' => 3.00, 'output_per_mtok' => 15.00, 'cache_read_per_mtok' => 0.30, 'cache_write_per_mtok' => 3.75, 'reasoning_per_mtok' => 0],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'input_per_mtok' => 3.00, 'output_per_mtok' => 15.00, 'cache_read_per_mtok' => 0.30, 'cache_write_per_mtok' => 3.75, 'reasoning_per_mtok' => 0],
            ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'input_per_mtok' => 1.00, 'output_per_mtok' => 5.00, 'cache_read_per_mtok' => 0.10, 'cache_write_per_mtok' => 1.25, 'reasoning_per_mtok' => 0],

            ['provider' => 'gemini', 'model' => 'gemini-3.1-pro-preview', 'input_per_mtok' => 2.00, 'output_per_mtok' => 12.00, 'cache_read_per_mtok' => 0.20, 'cache_write_per_mtok' => 2.00, 'reasoning_per_mtok' => 12.00],
            ['provider' => 'gemini', 'model' => 'gemini-3.1-flash-lite-preview', 'input_per_mtok' => 0.25, 'output_per_mtok' => 1.50, 'cache_read_per_mtok' => 0.025, 'cache_write_per_mtok' => 0.25, 'reasoning_per_mtok' => 1.50],
            ['provider' => 'gemini', 'model' => 'gemini-3-flash-preview', 'input_per_mtok' => 0.50, 'output_per_mtok' => 3.00, 'cache_read_per_mtok' => 0.05, 'cache_write_per_mtok' => 0.50, 'reasoning_per_mtok' => 3.00],
            ['provider' => 'gemini', 'model' => 'gemini-2.5-pro', 'input_per_mtok' => 1.25, 'output_per_mtok' => 10.00, 'cache_read_per_mtok' => 0.125, 'cache_write_per_mtok' => 1.25, 'reasoning_per_mtok' => 10.00],
            ['provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'input_per_mtok' => 0.30, 'output_per_mtok' => 2.50, 'cache_read_per_mtok' => 0.03, 'cache_write_per_mtok' => 0.30, 'reasoning_per_mtok' => 2.50],
            ['provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'input_per_mtok' => 0.10, 'output_per_mtok' => 0.40, 'cache_read_per_mtok' => 0.01, 'cache_write_per_mtok' => 0.10, 'reasoning_per_mtok' => 0],

            ['provider' => 'xai', 'model' => 'grok-4.1-fast', 'input_per_mtok' => 0.20, 'output_per_mtok' => 0.50, 'cache_read_per_mtok' => 0.05, 'cache_write_per_mtok' => 0.20, 'reasoning_per_mtok' => 0],
            ['provider' => 'xai', 'model' => 'grok-4.1-fast-thinking', 'input_per_mtok' => 0.20, 'output_per_mtok' => 0.50, 'cache_read_per_mtok' => 0.05, 'cache_write_per_mtok' => 0.20, 'reasoning_per_mtok' => 0.50],
            ['provider' => 'xai', 'model' => 'grok-4-fast', 'input_per_mtok' => 0.20, 'output_per_mtok' => 0.50, 'cache_read_per_mtok' => 0.05, 'cache_write_per_mtok' => 0.20, 'reasoning_per_mtok' => 0],
            ['provider' => 'xai', 'model' => 'grok-code-fast-1', 'input_per_mtok' => 0.20, 'output_per_mtok' => 1.50, 'cache_read_per_mtok' => 0.02, 'cache_write_per_mtok' => 0.20, 'reasoning_per_mtok' => 0],
            ['provider' => 'xai', 'model' => 'grok-4', 'input_per_mtok' => 3.00, 'output_per_mtok' => 15.00, 'cache_read_per_mtok' => 0.75, 'cache_write_per_mtok' => 3.00, 'reasoning_per_mtok' => 15.00],
            ['provider' => 'xai', 'model' => 'grok-3-mini', 'input_per_mtok' => 0.25, 'output_per_mtok' => 0.50, 'cache_read_per_mtok' => 0.075, 'cache_write_per_mtok' => 0.25, 'reasoning_per_mtok' => 0],

            ['provider' => 'deepseek', 'model' => 'deepseek-v4-flash', 'input_per_mtok' => 0.14, 'output_per_mtok' => 0.28, 'cache_read_per_mtok' => 0.0028, 'cache_write_per_mtok' => 0.14, 'reasoning_per_mtok' => 0.28],
            ['provider' => 'deepseek', 'model' => 'deepseek-v4-pro', 'input_per_mtok' => 0.435, 'output_per_mtok' => 0.87, 'cache_read_per_mtok' => 0.0036, 'cache_write_per_mtok' => 0.435, 'reasoning_per_mtok' => 0.87],

            ['provider' => 'mistral', 'model' => 'mistral-large-2512', 'input_per_mtok' => 0.50, 'output_per_mtok' => 1.50, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'mistral', 'model' => 'mistral-large-2411', 'input_per_mtok' => 2.00, 'output_per_mtok' => 6.00, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'mistral', 'model' => 'mistral-medium-3.1', 'input_per_mtok' => 0.40, 'output_per_mtok' => 2.00, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'mistral', 'model' => 'mistral-small-3.2', 'input_per_mtok' => 0.075, 'output_per_mtok' => 0.20, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'mistral', 'model' => 'magistral-medium', 'input_per_mtok' => 2.00, 'output_per_mtok' => 5.00, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 5.00],
            ['provider' => 'mistral', 'model' => 'codestral-2508', 'input_per_mtok' => 0.30, 'output_per_mtok' => 0.90, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'mistral', 'model' => 'devstral-2-2512', 'input_per_mtok' => 0.40, 'output_per_mtok' => 0.90, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],

            ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b', 'input_per_mtok' => 0.15, 'output_per_mtok' => 0.60, 'cache_read_per_mtok' => 0.075, 'cache_write_per_mtok' => 0.15, 'reasoning_per_mtok' => 0],
            ['provider' => 'groq', 'model' => 'openai/gpt-oss-20b', 'input_per_mtok' => 0.075, 'output_per_mtok' => 0.30, 'cache_read_per_mtok' => 0.0375, 'cache_write_per_mtok' => 0.075, 'reasoning_per_mtok' => 0],
            ['provider' => 'groq', 'model' => 'meta-llama/llama-4-scout-17b-16e-instruct', 'input_per_mtok' => 0.11, 'output_per_mtok' => 0.34, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'groq', 'model' => 'qwen/qwen3-32b', 'input_per_mtok' => 0.29, 'output_per_mtok' => 0.59, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'groq', 'model' => 'moonshotai/kimi-k2-instruct-0905', 'input_per_mtok' => 1.00, 'output_per_mtok' => 3.00, 'cache_read_per_mtok' => 0.50, 'cache_write_per_mtok' => 1.00, 'reasoning_per_mtok' => 0],
            ['provider' => 'groq', 'model' => 'llama-3.3-70b-versatile', 'input_per_mtok' => 0.59, 'output_per_mtok' => 0.79, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'groq', 'model' => 'llama-3.1-8b-instant', 'input_per_mtok' => 0.05, 'output_per_mtok' => 0.08, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],

            ['provider' => 'cohere', 'model' => 'command-a-03-2025', 'input_per_mtok' => 2.50, 'output_per_mtok' => 10.00, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'cohere', 'model' => 'command-r-plus-08-2024', 'input_per_mtok' => 2.50, 'output_per_mtok' => 10.00, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'cohere', 'model' => 'command-r-08-2024', 'input_per_mtok' => 0.15, 'output_per_mtok' => 0.60, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'cohere', 'model' => 'command-r7b-12-2024', 'input_per_mtok' => 0.0375, 'output_per_mtok' => 0.15, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
        ];
    }
};
