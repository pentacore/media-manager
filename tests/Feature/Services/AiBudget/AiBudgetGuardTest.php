<?php

declare(strict_types=1);

use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Notifications\AiBudgetSoftLimitReached;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Settings\AiSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    AiModelPrice::create([
        'provider' => 'openai',
        'model' => 'test-model',
        'input_per_mtok' => 1.0,
        'output_per_mtok' => 2.0,
    ]);
});

function recordSpend(int $promptTokens, int $completionTokens): void
{
    AiUsageRecord::create([
        'invocation_id' => 'test-'.bin2hex(random_bytes(8)),
        'agent_class' => 'TestAgent',
        'provider' => 'openai',
        'model' => 'test-model',
        'prompt_tokens' => $promptTokens,
        'completion_tokens' => $completionTokens,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'completed',
    ]);
}

test('no caps configured is a no-op', function (): void {
    Notification::fake();

    recordSpend(1_000_000, 1_000_000);

    resolve(AiBudgetGuard::class)->enforce();

    Notification::assertNothingSent();
});

test('throws once spend reaches the hard cap', function (): void {
    resolve(AiSettings::class)->setHardBudgetUsd(1.0);

    // 1M input * $1 + 1M output * $2 = $3 — over the $1 cap.
    recordSpend(1_000_000, 1_000_000);

    expect(fn () => resolve(AiBudgetGuard::class)->enforce())
        ->toThrow(AiBudgetExceededException::class);
});

test('does not throw when spend is below the hard cap', function (): void {
    resolve(AiSettings::class)->setHardBudgetUsd(100.0);

    recordSpend(100_000, 100_000); // ~$0.30

    resolve(AiBudgetGuard::class)->enforce();
})->throwsNoExceptions();

test('soft limit fires a one-shot notification per calendar month', function (): void {
    Notification::fake();

    User::factory()->admin()->count(2)->create();

    resolve(AiSettings::class)->setSoftBudgetUsd(2.0);

    recordSpend(1_000_000, 1_000_000); // $3 — over the soft cap

    resolve(AiBudgetGuard::class)->enforce();
    resolve(AiBudgetGuard::class)->enforce();
    resolve(AiBudgetGuard::class)->enforce();

    Notification::assertSentTimes(AiBudgetSoftLimitReached::class, 2);
});

test('soft limit does not fire when spend is below the cap', function (): void {
    Notification::fake();

    User::factory()->admin()->create();

    resolve(AiSettings::class)->setSoftBudgetUsd(100.0);

    recordSpend(100_000, 100_000);

    resolve(AiBudgetGuard::class)->enforce();

    Notification::assertNothingSent();
});

test('saving a new soft cap clears the notified-at stamp so it can fire again', function (): void {
    $aiSettings = resolve(AiSettings::class);

    $aiSettings->markSoftBudgetNotified('2026-04-15T12:00:00+00:00');

    expect($aiSettings->softBudgetNotifiedAt())->not->toBeNull();

    $aiSettings->setSoftBudgetUsd(5.0);

    expect($aiSettings->softBudgetNotifiedAt())->toBeNull();
});

test('hard cap exception carries the spend and cap for the controller', function (): void {
    resolve(AiSettings::class)->setHardBudgetUsd(1.0);
    recordSpend(1_000_000, 1_000_000);

    try {
        resolve(AiBudgetGuard::class)->enforce();
        $this->fail('expected exception');
    } catch (AiBudgetExceededException $aiBudgetExceededException) {
        expect($aiBudgetExceededException->hardCapUsd)->toBe(1.0)
            ->and($aiBudgetExceededException->spendUsd)->toBeGreaterThan(2.0);
    }
});

test('spend is scoped to the current calendar month', function (): void {
    resolve(AiSettings::class)->setHardBudgetUsd(1.0);

    $row = AiUsageRecord::create([
        'invocation_id' => 'test-'.bin2hex(random_bytes(8)),
        'agent_class' => 'TestAgent',
        'provider' => 'openai',
        'model' => 'test-model',
        'prompt_tokens' => 10_000_000,
        'completion_tokens' => 10_000_000,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'completed',
    ]);

    // Backdate to last month.
    AiUsageRecord::where('id', $row->id)->update([
        'created_at' => CarbonImmutable::now()->startOfMonth()->subDay(),
    ]);

    // No current-month spend → guard is a no-op.
    resolve(AiBudgetGuard::class)->enforce();
})->throwsNoExceptions();
