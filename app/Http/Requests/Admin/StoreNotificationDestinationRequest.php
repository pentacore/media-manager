<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\NotificationDestinationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreNotificationDestinationRequest extends FormRequest
{
    use NotificationDestinationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->destinationRules(secretsOptional: false);
    }
}
