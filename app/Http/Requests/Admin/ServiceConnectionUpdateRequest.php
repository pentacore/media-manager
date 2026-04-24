<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ServiceType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ServiceConnectionUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', ServiceType::validationRule()],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:500'],
            // Secrets are optional on update: blank means "keep existing value".
            // The controller filters empty strings so they never overwrite state.
            'api_key' => ['nullable', 'string', 'max:500'],
            'webhook_token' => ['nullable', 'string', 'min:10', 'max:500'],
        ];
    }
}
