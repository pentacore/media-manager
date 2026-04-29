<?php

declare(strict_types=1);

namespace App\Services\AiBudget;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\AiBudgetSoftLimitReached;
use App\Services\AiUsage\AiUsageReporting;
use App\Settings\AiSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Enforces the per-month AI spend caps configured in AiSettings.
 *
 * - Soft cap: notify each admin once per calendar month, then keep
 *   serving requests.
 * - Hard cap: throw AiBudgetExceededException so callers (the chat
 *   controller) can short-circuit with a 402-style refusal.
 *
 * Spend is computed against the running calendar month using the same
 * cost expression as the AI Usage page, so what the admin sees on the
 * dashboard is exactly what the guard checks.
 */
class AiBudgetGuard
{
    public function __construct(
        private readonly AiSettings $aiSettings,
        private readonly AiUsageReporting $aiUsageReporting,
    ) {}

    /**
     * Throws if the hard cap has been hit. Otherwise side-effects: fires
     * the soft-limit notification (once per calendar month) when spend
     * crosses the soft cap. Safe to call before every AI request — the
     * spend query is a single aggregate row.
     */
    public function enforce(): void
    {
        $hard = $this->aiSettings->hardBudgetUsd();
        $soft = $this->aiSettings->softBudgetUsd();

        if ($hard === null && $soft === null) {
            return;
        }

        $spend = $this->currentMonthSpend();

        throw_if($hard !== null && $spend >= $hard, AiBudgetExceededException::class, $spend, $hard);

        if ($soft !== null && $spend >= $soft && $this->shouldNotifySoftLimit()) {
            $this->dispatchSoftLimitNotification($spend, $soft);
        }
    }

    public function currentMonthSpend(): float
    {
        $since = CarbonImmutable::now()->startOfMonth();

        return (float) $this->aiUsageReporting->totals($since)['total_cost'];
    }

    /**
     * @return array{soft: ?float, hard: ?float, spend: float, soft_notified_at: ?string}
     */
    public function snapshot(): array
    {
        return [
            'soft' => $this->aiSettings->softBudgetUsd(),
            'hard' => $this->aiSettings->hardBudgetUsd(),
            'spend' => $this->currentMonthSpend(),
            'soft_notified_at' => $this->aiSettings->softBudgetNotifiedAt(),
        ];
    }

    /**
     * Within the current calendar month? If yes, the notification has
     * already been sent and we shouldn't re-send. Comparing only year +
     * month means a cap that's been raised mid-month doesn't re-trigger
     * — the user explicitly resets that by saving the cap again, which
     * clears `soft_notified_at` in AiSettings::setSoftBudgetUsd().
     */
    private function shouldNotifySoftLimit(): bool
    {
        $stamp = $this->aiSettings->softBudgetNotifiedAt();

        if ($stamp === null) {
            return true;
        }

        try {
            $when = CarbonImmutable::parse($stamp);
        } catch (Throwable) {
            return true;
        }

        $now = CarbonImmutable::now();

        return $when->year !== $now->year || $when->month !== $now->month;
    }

    private function dispatchSoftLimitNotification(float $spend, float $soft): void
    {
        $admins = User::query()->where('role', UserRole::Admin)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new AiBudgetSoftLimitReached($spend, $soft));
        }

        $this->aiSettings->markSoftBudgetNotified();
    }
}
