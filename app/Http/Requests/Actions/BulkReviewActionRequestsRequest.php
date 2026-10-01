<?php

declare(strict_types=1);

namespace App\Http\Requests\Actions;

use App\Concerns\BulkActionValidationRules;
use App\Enums\ActionQueueBulkAction;
use Illuminate\Foundation\Http\FormRequest;

class BulkReviewActionRequestsRequest extends FormRequest
{
    use BulkActionValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->bulkIdRules(),
            'action' => ['required', ActionQueueBulkAction::validationRule()],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
