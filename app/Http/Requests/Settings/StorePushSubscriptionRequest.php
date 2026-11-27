<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\Notifications\WebPushConfig;
use Closure;
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
            'keys.p256dh' => ['required', 'string', 'max:255', $this->base64UrlBytes(65, "\x04")],
            'keys.auth' => ['required', 'string', 'max:255', $this->base64UrlBytes(16)],
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

    /**
     * A base64url string (no padding) that decodes to exactly $length bytes,
     * optionally starting with $firstByte.
     */
    private function base64UrlBytes(int $length, ?string $firstByte = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($length, $firstByte): void {
            $decoded = is_string($value) && preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1
                ? base64_decode(strtr($value, '-_', '+/'), true)
                : false;

            if ($decoded === false || strlen($decoded) !== $length || ($firstByte !== null && $decoded[0] !== $firstByte)) {
                $fail(__('The :attribute is not a valid push key.'));
            }
        };
    }
}
