<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\NotificationDestinationValidationRules;
use App\Services\Notifications\PreferenceResolver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    use NotificationDestinationValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'preferences' => ['present', 'array'],
            'preferences.*.class' => ['required', 'string'],
            'preferences.*.severities' => ['required', 'array'],
            'ntfy_topic' => ['nullable', ...self::ntfyTopicRules()],
            // Secrets: absent key = keep, '' = clear, value = replace (see controller).
            'discord_webhook_url' => ['sometimes', 'nullable', ...self::discordWebhookUrlRules()],
            'telegram_chat_id' => ['nullable', ...self::telegramChatIdRules()],
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
        return self::destinationMessages(
            urlAttribute: 'discord_webhook_url',
            chatIdAttribute: 'telegram_chat_id',
            topicAttribute: 'ntfy_topic',
        );
    }
}
