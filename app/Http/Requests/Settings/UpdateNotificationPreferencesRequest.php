<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\NotificationDestinationValidationRules;
use App\Services\Notifications\PreferenceResolver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    use NotificationDestinationValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // The generic webhook channel POSTs to whatever URL is saved, with no
        // SSRF filtering — admin-only, so only trusted operators can point it
        // anywhere.
        $webhookProhibited = Rule::prohibitedIf(fn (): bool => ! $this->isAdminUser());

        $rules = [
            'preferences' => ['present', 'array'],
            'preferences.*.class' => ['required', 'string'],
            'preferences.*.severities' => ['required', 'array'],
            'ntfy_topic' => ['nullable', ...self::ntfyTopicRules()],
            // Secrets: absent key = keep, '' = clear, value = replace (see controller).
            'discord_webhook_url' => ['sometimes', 'nullable', ...self::discordWebhookUrlRules()],
            'telegram_chat_id' => ['nullable', ...self::telegramChatIdRules()],
            'webhook_url' => ['nullable', 'url', 'max:2048', $webhookProhibited],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255', $webhookProhibited],
        ];

        foreach (PreferenceResolver::CHANNELS as $channel) {
            $rules['preferences.*.severities.*.'.$channel] = ['boolean'];
        }

        return $rules;
    }

    private function isAdminUser(): bool
    {
        return $this->user()?->isAdmin() ?? false;
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
