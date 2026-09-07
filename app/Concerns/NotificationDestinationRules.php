<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\NotificationSeverity;
use App\Enums\PushChannelType;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation shared by the store/update destination requests. Config rules
 * are keyed by the submitted channel; `$secretsOptional` lets an update
 * leave encrypted fields blank to keep the stored value.
 */
trait NotificationDestinationRules
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
                'config.topic' => ['required', 'string', 'max:255', 'regex:/^[-_A-Za-z0-9]+$/'],
            ],
            PushChannelType::Discord => [
                'config.url' => [$requiredUnlessKept, 'url', 'max:2048', 'starts_with:https://discord.com/api/webhooks/,https://discordapp.com/api/webhooks/'],
            ],
            PushChannelType::Telegram => [
                'config.chat_id' => ['required', 'string', 'max:32', 'regex:/^-?\d+$/'],
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
        return [
            'config.url.starts_with' => 'Paste the webhook URL Discord gives you (https://discord.com/api/webhooks/…).',
            'config.chat_id.regex' => 'A Telegram chat id is a (possibly negative) number.',
            'config.topic.regex' => 'Topics may contain letters, digits, dashes and underscores only.',
        ];
    }
}
