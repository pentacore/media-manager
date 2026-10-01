<?php

declare(strict_types=1);

namespace App\Http\Requests\Sabnzbd;

use App\Concerns\BulkActionValidationRules;
use App\Enums\SabnzbdBulkAction;
use App\Services\Sabnzbd\SabnzbdClient;
use Illuminate\Foundation\Http\FormRequest;

class BulkSabnzbdSlotsRequest extends FormRequest
{
    use BulkActionValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->bulkIdRules(),
            // nzo_ids are strings; anchored so nothing else can ride into
            // SABnzbd's API query string (smuggled params, path traversal).
            'ids.*' => ['required', 'string', 'distinct', sprintf('regex:/^%s$/', SabnzbdClient::NZO_ID_PATTERN)],
            'action' => ['required', SabnzbdBulkAction::validationRule()],
        ];
    }

    /**
     * @return list<string>
     */
    public function nzoIds(): array
    {
        return array_values(array_map(strval(...), (array) $this->validated('ids')));
    }
}
