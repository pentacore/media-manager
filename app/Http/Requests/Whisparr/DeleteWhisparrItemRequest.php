<?php

declare(strict_types=1);

namespace App\Http\Requests\Whisparr;

use App\Concerns\WhisparrActionValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class DeleteWhisparrItemRequest extends FormRequest
{
    use WhisparrActionValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->whisparrItemRules(),
            'delete_files' => ['sometimes', 'boolean'],
        ];
    }
}
