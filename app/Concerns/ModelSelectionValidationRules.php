<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Ai\ModelCatalog;
use Illuminate\Validation\Rule;

trait ModelSelectionValidationRules
{
    /**
     * Rules for a `*_provider` field paired with a model setting: a configured
     * provider laravel/ai can serve text requests with.
     *
     * @return array<int, mixed>
     */
    protected function modelProviderRules(): array
    {
        return ['string', Rule::in(resolve(ModelCatalog::class)->textProviders())];
    }
}
