<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Ai\ModelCatalog;
use App\Enums\AiReasoningLevel;
use App\Models\AiModelPrice;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared rules for a provider + model + reasoning selection and for tier lists
 * of them (AI Models page, conversation overrides, chat template presets). The
 * model must be a priced model of the chosen provider; `auto` is allowed where
 * noted.
 */
trait AiModelSelectionValidationRules
{
    /**
     * Rules for `{prefix}.provider|model|reasoning`, or the bare keys when
     * the prefix is ''.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function selectionRules(string $prefix, bool $withReasoning = true): array
    {
        return [
            $this->selectionKey($prefix, 'provider') => ['nullable', 'string', Rule::in(resolve(ModelCatalog::class)->textProviders())],
            $this->selectionKey($prefix, 'model') => ['nullable', 'string', 'max:100'],
            ...($withReasoning ? [$this->selectionKey($prefix, 'reasoning') => ['nullable', 'string', AiReasoningLevel::validationRule()]] : []),
        ];
    }

    protected function validatePricedSelection(Validator $validator, string $prefix, mixed $provider, mixed $model, bool $allowAuto = false): void
    {
        // Non-string values are reported by the field rules; this hook still runs after them.
        if (! is_null($provider) && ! is_string($provider) || ! is_null($model) && ! is_string($model)) {
            return;
        }

        if (blank($model)) {
            return;
        }

        if (blank($provider)) {
            $validator->errors()->add($this->selectionKey($prefix, 'provider'), __('Pick a provider for this model.'));

            return;
        }

        if ($allowAuto && $model === 'auto') {
            return;
        }

        $priced = AiModelPrice::query()->where('provider', $provider)->where('model', $model)->exists();

        if (! $priced) {
            $validator->errors()->add(
                $this->selectionKey($prefix, 'model'),
                __(':model is not a priced :provider model.', ['model' => $model, 'provider' => $provider]),
            );
        }
    }

    /**
     * Rules for one tier of a tier list at `{prefix}`: a selection plus the
     * optional pool minimums.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function tierRules(string $prefix): array
    {
        return [
            $prefix => ['required', 'array:provider,model,reasoning,min_pool_percent,min_pool_tokens'],
            ...$this->selectionRules($prefix),
            $this->selectionKey($prefix, 'min_pool_percent') => ['nullable', 'integer', 'between:1,100'],
            $this->selectionKey($prefix, 'min_pool_tokens') => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Cross-field checks for a tier list at `{prefix}` (e.g.
     * `tasks.chat.tiers`): each tier names a priced model, only the last tier
     * may inherit, the last tier has no conditions, and conditions sit only on
     * a named model (never `auto`) that belongs to a free pool.
     */
    protected function validateTierList(Validator $validator, string $prefix, mixed $tiers, bool $allowAuto = false): void
    {
        if (! is_array($tiers) || ! array_is_list($tiers)) {
            return;
        }

        $last = count($tiers) - 1;

        foreach ($tiers as $index => $tier) {
            if (! is_array($tier)) {
                continue;
            }

            $key = sprintf('%s.%d', $prefix, $index);
            $provider = $tier['provider'] ?? null;
            $model = $tier['model'] ?? null;

            $this->validatePricedSelection($validator, $key, $provider, $model, $allowAuto);

            if (blank($model) && $index !== $last) {
                $validator->errors()->add($this->selectionKey($key, 'model'), __('Pick a model for this tier.'));

                continue;
            }

            $conditionField = match (true) {
                filled($tier['min_pool_percent'] ?? null) => 'min_pool_percent',
                filled($tier['min_pool_tokens'] ?? null) => 'min_pool_tokens',
                default => null,
            };

            if ($conditionField === null) {
                continue;
            }

            $conditionKey = $this->selectionKey($key, $conditionField);

            if ($index === $last) {
                $validator->errors()->add($conditionKey, __('The last tier always runs; remove its conditions.'));
            } elseif (! is_string($model) || $model === 'auto') {
                $validator->errors()->add($conditionKey, __('Only a tier with a model can have conditions.'));
            } elseif (is_string($provider) && ! $this->belongsToFreePool($provider, $model)) {
                $validator->errors()->add($conditionKey, __(':model has no free pool; remove the condition.', ['model' => $model]));
            }
        }
    }

    private function belongsToFreePool(string $provider, string $model): bool
    {
        return AiModelPrice::query()
            ->where('provider', $provider)
            ->where('model', $model)
            ->whereNotNull('free_usage_pool_id')
            ->exists();
    }

    private function selectionKey(string $prefix, string $field): string
    {
        return $prefix === '' ? $field : sprintf('%s.%s', $prefix, $field);
    }
}
