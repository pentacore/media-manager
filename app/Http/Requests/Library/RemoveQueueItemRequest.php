<?php

declare(strict_types=1);

namespace App\Http\Requests\Library;

use App\Concerns\PinnedConnectionValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RemoveQueueItemRequest extends FormRequest
{
    use PinnedConnectionValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->pinnedConnectionRules(),
            // remove | block — an unknown verb is answered with a toast by
            // the controller, as before.
            'verb' => ['sometimes', 'string'],
        ];
    }
}
