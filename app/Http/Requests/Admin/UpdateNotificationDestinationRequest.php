<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\NotificationDestinationValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationDestinationRequest extends FormRequest
{
    use NotificationDestinationValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->destinationRules(secretsOptional: true);
    }
}
