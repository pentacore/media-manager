<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\AiMode;
use App\Enums\AiReasoningLevel;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;

class AiSettings
{
    public const MODE_KEY = 'ai.mode';

    public const MODEL_KEY = 'ai.model';

    public const TITLE_MODEL_KEY = 'ai.title_model';

    /**
     * Sentinel that resolves to the default provider's cheapest text model
     * at call time rather than pinning a specific model name.
     */
    public const AUTO_MODEL = 'auto';

    public const string FAILOVER_PROVIDER_KEY = 'ai.failover_provider';

    public const SOFT_BUDGET_KEY = 'ai.budget.soft_monthly_usd';

    public const HARD_BUDGET_KEY = 'ai.budget.hard_monthly_usd';

    public const SOFT_BUDGET_NOTIFIED_AT_KEY = 'ai.budget.soft_notified_at';

    public const MEDIA_ADVISOR_REASONING_LEVEL_KEY = 'ai.advisor_reasoning_level';

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

    /**
     * The model used to generate conversation titles.
     *
     * The persisted `auto` sentinel is translated to the default provider's
     * cheapest text model here, so every caller — including the queued job
     * that passes this value as an explicit `model:` argument — sends a
     * concrete model name rather than the literal `auto` string.
     */
    public function titleModel(): string
    {
        $value = (string) $this->appSettings->get(
            self::TITLE_MODEL_KEY,
            config('mediamanager.ai.title_model', 'gpt-5.4-nano'),
        );

        if ($value === self::AUTO_MODEL) {
            return Ai::textProvider()->cheapestTextModel();
        }

        return $value !== '' ? $value : 'gpt-5.4-nano';
    }

    public function setAdvisorReasoningLevel(AiReasoningLevel $aiReasoningLevel): void
    {
        $this->appSettings->set(self::MEDIA_ADVISOR_REASONING_LEVEL_KEY, $aiReasoningLevel->value);
    }

    public function advisorReasoningLevel(): string
    {
        return (string) $this->appSettings->get(
            self::MEDIA_ADVISOR_REASONING_LEVEL_KEY,
            config('mediamanager.ai.advisor_reasoning_level', AiReasoningLevel::None->value),
        );
    }

    public function setTitleModel(string $model): void
    {
        $this->appSettings->set(self::TITLE_MODEL_KEY, $model);
    }

    /**
     * The provider text requests fall back to when the primary provider
     * raises a failoverable error. Null = no failover (SDK default only).
     */
    public function failoverProvider(): ?Lab
    {
        $value = (string) $this->appSettings->get(self::FAILOVER_PROVIDER_KEY, '');

        return $value !== '' ? Lab::tryFrom($value) : null;
    }

    public function setFailoverProvider(?Lab $lab): void
    {
        $this->appSettings->set(self::FAILOVER_PROVIDER_KEY, $lab?->value ?? '');
    }

    /**
     * Primary-then-failover provider chain for prompt()/stream() calls, or
     * null when no failover is configured (callers omit the provider arg and
     * fall back to the SDK default). The primary is the configured default
     * text provider (`ai.default`); when it equals the failover the chain
     * collapses to null since there is nothing to fail over to.
     *
     * @return array<int, Lab>|null
     */
    public function providerChain(): ?array
    {
        $failover = $this->failoverProvider();

        if (! $failover instanceof Lab) {
            return null;
        }

        $primary = $this->primaryProvider();

        if ($primary === $failover) {
            return null;
        }

        return [$primary, $failover];
    }

    /**
     * The failover chain expressed as a per-provider model map for a caller
     * that pins an explicit primary model.
     *
     * A plain list array (`[$primary, $failover]`) makes the SDK ignore the
     * agent's own model() entirely and resolve each provider's default model
     * — so the primary would lose its configured model. This map keeps the
     * caller's model on the primary and lets the failover provider use its
     * own default (null), which also guarantees the OpenAI-shaped model never
     * leaks onto a non-OpenAI failover provider.
     *
     * @return array<string, string|null>|null
     */
    public function providerChainWithModel(string $primaryModel): ?array
    {
        $chain = $this->providerChain();

        if ($chain === null) {
            return null;
        }

        [$primary, $failover] = $chain;

        return [
            $primary->value => $primaryModel,
            $failover->value => null,
        ];
    }

    private function primaryProvider(): Lab
    {
        return Lab::tryFrom((string) config('ai.default', 'openai')) ?? Lab::OpenAI;
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
