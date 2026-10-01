<?php

declare(strict_types=1);

namespace App\Concerns;

trait AiModelPriceSelectionValidationRules
{
    /**
     * The model price rows a bulk action targets.
     *
     * @return array<string, array<mixed>>
     */
    protected function aiModelPriceSelectionRules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:ai_model_prices,id'],
        ];
    }
}
