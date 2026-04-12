<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ServiceType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceConnectionUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(ServiceType::class)],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:500'],
            'api_key' => ['required', 'string', 'max:500'],
            'webhook_token' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
