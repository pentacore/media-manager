<?php

declare(strict_types=1);

namespace App\Concerns;

/**
 * The query every subtitle page accepts: the selected Bazarr connection and
 * the inventory paging.
 */
trait BazarrPageValidationRules
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function bazarrPageRules(): array
    {
        return [
            /** @query */
            'connection' => ['nullable', 'integer', 'min:1'],
            /** @query */
            'page' => ['nullable', 'integer', 'min:1'],
            /** @query */
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
