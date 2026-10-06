<?php

declare(strict_types=1);

namespace App\Concerns;

trait AiModelPriceSelectionValidationRules
{
    /**
     * The model price rows a bulk action targets. The catalog page loads
     * every row unpaginated and "select all" selects every visible row, so
     * the cap only needs to sit comfortably above a realistic catalog size
     * (the models.dev + LiteLLM merge is a few hundred rows today) — 500
     * rejects a malformed or scripted payload without ever limiting a real
     * "select all".
     *
     * @return array<string, array<mixed>>
     */
    protected function aiModelPriceSelectionRules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:ai_model_prices,id'],
        ];
    }
}
