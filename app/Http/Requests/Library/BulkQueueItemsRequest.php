<?php

declare(strict_types=1);

namespace App\Http\Requests\Library;

use App\Concerns\BulkActionValidationRules;
use App\Enums\QueueBulkAction;
use App\Enums\ServiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkQueueItemsRequest extends FormRequest
{
    use BulkActionValidationRules;

    /**
     * Unlike the media-action surfaces, the queue is not pinned to a
     * `service_connection_id`: bulk resolves the same way the single-item
     * remove path does — the active connection for `service`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service' => ['required', Rule::in([ServiceType::Sonarr->value, ServiceType::Radarr->value])],
            ...$this->bulkIdRules(),
            'action' => ['required', QueueBulkAction::validationRule()],
        ];
    }
}
