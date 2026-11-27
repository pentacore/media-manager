<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\Notifications\WebPushConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePushSubscriptionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'url:https', 'max:2048'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['sometimes', 'nullable', 'in:aes128gcm,aesgcm'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $endpoint = $this->input('endpoint');

            if (is_string($endpoint) && ! $validator->errors()->has('endpoint') && ! resolve(WebPushConfig::class)->allowsEndpoint($endpoint)) {
                $validator->errors()->add('endpoint', __('This push service is not on the allowed list.'));
            }
        });
    }
}
