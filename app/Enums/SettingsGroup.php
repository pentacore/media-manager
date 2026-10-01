<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;
use App\Settings\AiSettings;

/**
 * The admin settings surfaces the audit log records as `settings.updated`
 * rows (subject = the case value). Key-backed groups live in app_settings
 * under their prefixes; record-backed groups (notification destinations,
 * AI free pools, AI model prices) diff the saved row instead.
 */
enum SettingsGroup: string
{
    use EnumUtils;

    case Ai = 'ai';
    case DecisionAgent = 'decision_agent';
    case MediaReplacement = 'media_replacement';
    case BazarrAutomation = 'bazarr_automation';
    case Webhooks = 'webhooks';
    case NotificationDestinations = 'notification_destinations';
    case AiFreeUsagePools = 'ai_free_usage_pools';
    case AiModelPrices = 'ai_model_prices';

    public function label(): string
    {
        return match ($this) {
            self::Ai => 'AI',
            self::DecisionAgent => 'Decision agent',
            self::MediaReplacement => 'Media replacement',
            self::BazarrAutomation => 'Bazarr automation',
            self::Webhooks => 'Webhook',
            self::NotificationDestinations => 'Notification destination',
            self::AiFreeUsagePools => 'AI free usage pool',
            self::AiModelPrices => 'AI model price',
        };
    }

    /**
     * app_settings key prefixes this group owns; empty for record-backed groups.
     *
     * @return list<string>
     */
    public function settingKeyPrefixes(): array
    {
        return match ($this) {
            self::Ai => ['ai.'],
            self::DecisionAgent => ['decision_agent.'],
            self::MediaReplacement => ['ai.media_replacement'],
            self::BazarrAutomation => ['bazarr.automation'],
            self::Webhooks => ['webhooks.'],
            self::NotificationDestinations, self::AiFreeUsagePools, self::AiModelPrices => [],
        };
    }

    /**
     * Keys under the prefixes that belong to another group or are bookkeeping
     * rather than configuration.
     *
     * @return list<string>
     */
    public function excludedSettingKeys(): array
    {
        return match ($this) {
            self::Ai => ['ai.media_replacement', AiSettings::SOFT_BUDGET_NOTIFIED_AT_KEY],
            default => [],
        };
    }
}
