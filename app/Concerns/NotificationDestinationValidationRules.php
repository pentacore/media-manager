<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\NotificationSeverity;
use App\Enums\PushChannelType;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation shared by the store/update destination requests and the per-user
 * notification preferences request. Config rules are keyed by the submitted
 * channel; `$secretsOptional` lets an update leave encrypted fields blank to
 * keep the stored value.
 */
trait NotificationDestinationValidationRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function destinationRules(bool $secretsOptional): array
    {
        $channel = PushChannelType::tryFrom((string) $this->input('channel'));
        $requiredUnlessKept = $secretsOptional ? 'nullable' : 'required';

        $config = match ($channel) {
            PushChannelType::Ntfy => [
                'config.topic' => ['required', ...self::ntfyTopicRules()],
            ],
            PushChannelType::Discord => [
                'config.url' => [$requiredUnlessKept, ...self::discordWebhookUrlRules()],
            ],
            PushChannelType::Telegram => [
                'config.chat_id' => ['required', ...self::telegramChatIdRules()],
            ],
            PushChannelType::Webhook => [
                'config.url' => ['required', 'url', 'max:2048'],
                'config.secret' => ['nullable', 'string', 'max:255'],
            ],
            null => [],
        };

        return [
            'channel' => ['required', PushChannelType::validationRule()],
            'label' => ['required', 'string', 'max:60'],
            'is_enabled' => ['nullable', 'boolean'],
            'min_severity' => ['required', NotificationSeverity::validationRule()],
            'config' => ['present', 'array'],
            ...$config,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::destinationMessages();
    }

    /**
     * @return list<string>
     */
    protected static function ntfyTopicRules(): array
    {
        return ['string', 'max:255', 'regex:/^[-_A-Za-z0-9]+$/'];
    }

    /**
     * @return list<string>
     */
    protected static function discordWebhookUrlRules(): array
    {
        return ['url', 'max:2048', 'starts_with:https://discord.com/api/webhooks/,https://discordapp.com/api/webhooks/'];
    }

    /**
     * @return list<string>
     */
    protected static function telegramChatIdRules(): array
    {
        return ['string', 'max:32', 'regex:/^-?\d+$/'];
    }

    /**
     * Custom messages for the rules above, keyed by the attribute names the
     * calling request uses.
     *
     * @return array<string, string>
     */
    protected static function destinationMessages(
        string $urlAttribute = 'config.url',
        string $chatIdAttribute = 'config.chat_id',
        string $topicAttribute = 'config.topic',
    ): array {
        return [
            $urlAttribute.'.starts_with' => 'Paste the webhook URL Discord gives you (https://discord.com/api/webhooks/…).',
            $chatIdAttribute.'.regex' => 'A Telegram chat id is a (possibly negative) number.',
            $topicAttribute.'.regex' => 'Topics may contain letters, digits, dashes and underscores only.',
        ];
    }
}
