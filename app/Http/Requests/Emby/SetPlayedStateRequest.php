<?php

declare(strict_types=1);

namespace App\Http\Requests\Emby;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SetPlayedStateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'played' => ['required', 'boolean'],
        ];
    }
}
