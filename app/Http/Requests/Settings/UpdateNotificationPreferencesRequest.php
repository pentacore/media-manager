<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\NotificationDestinationValidationRules;
use App\Services\Notifications\PreferenceResolver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    use NotificationDestinationValidationRules;

    /**
     * Fields the generic webhook channel is built from — admin-only, since it
     * POSTs to whatever URL is saved with no SSRF filtering.
     *
     * @var list<string>
     */
    private const array WEBHOOK_FIELDS = ['webhook_url', 'webhook_secret'];

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
     * A non-admin submitting a non-empty webhook_url/webhook_secret is
     * rejected; an absent or blank value is still fine (keeps/clears
     * semantics for the fields an admin already saved).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->isAdminUser()) {
                return;
            }

            foreach (self::WEBHOOK_FIELDS as $field) {
                $value = $this->input($field);

                if ($value !== null && $value !== '') {
                    $validator->errors()->add($field, __('Only admins can set the webhook channel.'));
                }
            }
        });
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
