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
     * Pinned like the single-item remove path: `service_connection_id` is the
     * connection the queue rows were rendered from (queue ids overlap
     * between instances).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service' => ['required', Rule::in([ServiceType::Sonarr->value, ServiceType::Radarr->value])],
            'service_connection_id' => ['required', 'integer', 'min:1'],
            ...$this->bulkIdRules(),
            'action' => ['required', QueueBulkAction::validationRule()],
        ];
    }
}
