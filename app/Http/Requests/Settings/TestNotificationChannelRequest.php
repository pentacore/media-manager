<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\PushChannelType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $channel = PushChannelType::tryFrom((string) $this->input('channel'));

            if ($channel === PushChannelType::Webhook && ! ($this->user()?->isAdmin() ?? false)) {
                $validator->errors()->add('test_channel', __('Only admins can test the :channel channel.', ['channel' => $channel->label()]));
            }
        });
    }
}
