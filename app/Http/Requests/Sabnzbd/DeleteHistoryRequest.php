<?php

declare(strict_types=1);

namespace App\Http\Requests\Sabnzbd;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DeleteHistoryRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'with_files' => ['required', 'boolean'],
        ];
    }
}
