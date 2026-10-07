<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Ai\ModelCatalog;
use App\Enums\AiReasoningLevel;
use App\Models\AiModelPrice;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared rules for a provider + model + reasoning selection (AI Models page,
 * conversation overrides, chat template presets). The model must be a
 * priced model of the chosen provider; `auto` is allowed where noted.
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

    protected function validatePricedSelection(Validator $validator, string $prefix, ?string $provider, ?string $model, bool $allowAuto = false): void
    {
        if (! filled($model)) {
            return;
        }

        if (! filled($provider)) {
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

    private function selectionKey(string $prefix, string $field): string
    {
        return $prefix === '' ? $field : sprintf('%s.%s', $prefix, $field);
    }
}
