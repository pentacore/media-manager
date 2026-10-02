<?php

declare(strict_types=1);

namespace App\Http\Requests\Library;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RemoveQueueItemRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The connection the queue row was rendered from; queue ids
            // overlap between instances, so the pin is mandatory.
            'service_connection_id' => ['required', 'integer', 'min:1'],
            // remove | block — an unknown verb is answered with a toast by
            // the controller, as before.
            'verb' => ['sometimes', 'string'],
        ];
    }
}
