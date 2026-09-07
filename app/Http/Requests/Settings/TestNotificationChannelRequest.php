<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\PushChannelType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TestNotificationChannelRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', PushChannelType::validationRule()],
        ];
    }
}
