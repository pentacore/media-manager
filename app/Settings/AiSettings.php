<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\AiMode;

class AiSettings
{
    public const MODE_KEY = 'ai.mode';

    public const MODEL_KEY = 'ai.model';

    public const TITLE_MODEL_KEY = 'ai.title_model';

    public const SOFT_BUDGET_KEY = 'ai.budget.soft_monthly_usd';

    public const HARD_BUDGET_KEY = 'ai.budget.hard_monthly_usd';

    public const SOFT_BUDGET_NOTIFIED_AT_KEY = 'ai.budget.soft_notified_at';

    /**
     * Per-request override that takes precedence over the persisted mode.
     * Used by the chat surface so a user can flip between Advisory and
     * Executive for a single turn without mutating global state.
     */
    private ?AiMode $aiMode = null;

    public function __construct(private readonly AppSettings $appSettings) {}

    public function mode(): AiMode
    {
        if ($this->aiMode instanceof AiMode) {
            return $this->aiMode;
        }

        $value = (string) $this->appSettings->get(
            self::MODE_KEY,
            config('mediamanager.ai.mode', AiMode::Executive->value),
        );

        return AiMode::tryFrom($value) ?? AiMode::Executive;
    }

    public function withMode(AiMode $aiMode): void
    {
        $this->aiMode = $aiMode;
    }

    public function model(): string
    {
        $value = (string) $this->appSettings->get(
            self::MODEL_KEY,
            config('mediamanager.ai.model', 'gpt-5-mini'),
        );

        return $value !== '' ? $value : 'gpt-5-mini';
    }

    public function setMode(AiMode $aiMode): void
    {
        $this->appSettings->set(self::MODE_KEY, $aiMode->value);
    }

    public function setModel(string $model): void
    {
        $this->appSettings->set(self::MODEL_KEY, $model);
    }

    public function titleModel(): string
    {
        $value = (string) $this->appSettings->get(
            self::TITLE_MODEL_KEY,
            config('mediamanager.ai.title_model', 'gpt-5.4-nano'),
        );

        return $value !== '' ? $value : 'gpt-5.4-nano';
    }

    public function setTitleModel(string $model): void
    {
        $this->appSettings->set(self::TITLE_MODEL_KEY, $model);
    }

    /**
     * Monthly soft cap in USD. Triggers a one-shot notification when
     * spend crosses this number; AI keeps running. Null = no soft cap.
     */
    public function softBudgetUsd(): ?float
    {
        return $this->budgetUsd(self::SOFT_BUDGET_KEY);
    }

    /**
     * Monthly hard cap in USD. Once reached, AI requests are refused
     * until the calendar month ticks over. Null = no hard cap.
     */
    public function hardBudgetUsd(): ?float
    {
        return $this->budgetUsd(self::HARD_BUDGET_KEY);
    }

    public function setSoftBudgetUsd(?float $usd): void
    {
        $this->appSettings->set(self::SOFT_BUDGET_KEY, $usd);
        // Resetting the cap clears the "already notified" stamp so a
        // new period (or a higher cap) gets a fresh notification when
        // the threshold is crossed again.
        $this->appSettings->set(self::SOFT_BUDGET_NOTIFIED_AT_KEY, null);
    }

    public function setHardBudgetUsd(?float $usd): void
    {
        $this->appSettings->set(self::HARD_BUDGET_KEY, $usd);
    }

    /**
     * Returns the most recent ISO date the soft-limit notification was
     * sent — used so we don't spam admins on every request once the
     * threshold has been crossed within the current month.
     */
    public function softBudgetNotifiedAt(): ?string
    {
        $value = $this->appSettings->get(self::SOFT_BUDGET_NOTIFIED_AT_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function markSoftBudgetNotified(?string $isoDate = null): void
    {
        $this->appSettings->set(
            self::SOFT_BUDGET_NOTIFIED_AT_KEY,
            $isoDate ?? now()->toIso8601String(),
        );
    }

    private function budgetUsd(string $key): ?float
    {
        $value = $this->appSettings->get($key);

        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;

        return $float > 0 ? $float : null;
    }
}
