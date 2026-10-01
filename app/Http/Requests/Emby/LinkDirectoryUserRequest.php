<?php

declare(strict_types=1);

namespace App\Http\Requests\Emby;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LinkDirectoryUserRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            // Emby ids are hex/uuid-like; the value is only ever matched
            // against the cached directory, never sent to Emby.
            'emby_user_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ];
    }
}
