<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\LibraryBulkAction;
use App\Services\Actions\BulkRunner;

trait BulkActionValidationRules
{
    /**
     * The id list every bulk endpoint takes: 1..100 distinct positive ids.
     * A surface with string ids (SABnzbd) overrides `ids.*`.
     *
     * @return array<string, mixed>
     */
    protected function bulkIdRules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', sprintf('max:%d', BulkRunner::MAX_ITEMS)],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function libraryBulkRules(): array
    {
        return [
            'action' => ['required', LibraryBulkAction::validationRule()],
            'quality_profile_id' => ['nullable', 'integer', 'min:1', sprintf('required_if:action,%s', LibraryBulkAction::QualityProfile->value)],
            'delete_files' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The validated ids as integers. Integer-id surfaces only: a surface
     * that overrides `ids.*` to strings (SABnzbd nzo_ids) must read its own
     * ids, because every string id would cast to 0 here.
     *
     * @return list<int>
     */
    public function bulkIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->validated('ids')));
    }
}
