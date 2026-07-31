<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\BazarrServiceMappingValidationRules;
use App\Enums\ServiceType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ServiceConnectionStoreRequest extends FormRequest
{
    use BazarrServiceMappingValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', ServiceType::validationRule()],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:500'],
            'external_url' => ['nullable', 'url', 'max:500'],
            'api_key' => ['required', 'string', 'max:500'],
            'webhook_token' => ['required', 'string', 'min:10', 'max:500'],
            ...$this->bazarrServiceMappingRules(),
            // Whisparr-only: which API generation this connection speaks.
            'whisparr_version' => ['nullable', 'string', 'in:v2,v3'],
        ];
    }
}
