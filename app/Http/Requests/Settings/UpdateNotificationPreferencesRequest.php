<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\Notifications\PreferenceResolver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'preferences' => ['present', 'array'],
            'preferences.*.class' => ['required', 'string'],
            'preferences.*.severities' => ['required', 'array'],
            'ntfy_topic' => ['nullable', 'string', 'max:255', 'regex:/^[-_A-Za-z0-9]+$/'],
            // Secrets: absent key = keep, '' = clear, value = replace (see controller).
            'discord_webhook_url' => ['sometimes', 'nullable', 'url', 'max:2048', 'starts_with:https://discord.com/api/webhooks/,https://discordapp.com/api/webhooks/'],
            'telegram_chat_id' => ['nullable', 'string', 'max:32', 'regex:/^-?\d+$/'],
            'webhook_url' => ['nullable', 'url', 'max:2048'],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];

        foreach (PreferenceResolver::CHANNELS as $channel) {
            $rules['preferences.*.severities.*.'.$channel] = ['boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'discord_webhook_url.starts_with' => 'Paste the webhook URL Discord gives you (https://discord.com/api/webhooks/…).',
            'telegram_chat_id.regex' => 'A Telegram chat id is a (possibly negative) number.',
        ];
    }
}
