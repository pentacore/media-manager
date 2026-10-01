<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\SettingsGroup;
use App\Models\AppSetting;
use Illuminate\Database\Eloquent\Builder;

/**
 * The stored values a settings group owns, read straight from app_settings
 * (AppSettings caches reads for 60 seconds, so it cannot see a save that
 * just happened). Controllers capture one before and one after a save and
 * hand both to AuditLogger::settingsUpdated().
 */
final readonly class SettingsSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function capture(SettingsGroup $settingsGroup): array
    {
        $prefixes = $settingsGroup->settingKeyPrefixes();

        if ($prefixes === []) {
            return [];
        }

        return AppSetting::query()
            ->where(function (Builder $builder) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    // `_` and `%` are LIKE wildcards; decision_agent.* must not match decisionXagent.*.
                    $builder->orWhere('key', 'like', addcslashes($prefix, '\\%_').'%');
                }
            })
            ->whereNotIn('key', $settingsGroup->excludedSettingKeys())
            ->orderBy('key')
            ->get()
            ->mapWithKeys(static fn (AppSetting $appSetting): array => [$appSetting->key => $appSetting->value])
            ->all();
    }
}
