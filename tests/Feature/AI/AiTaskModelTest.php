<?php

declare(strict_types=1);

use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use Illuminate\Database\UniqueConstraintViolationException;

test('a task model row casts its task and reasoning', function (): void {
    $row = AiTaskModel::factory()
        ->task(AiTask::Title)
        ->selecting('openai', 'gpt-5.4-nano')
        ->reasoning(AiReasoningLevel::Low)
        ->create();

    $fresh = $row->fresh();

    expect($fresh->task)->toBe(AiTask::Title)
        ->and($fresh->scope)->toBe(AiTaskModel::DEFAULT_SCOPE)
        ->and($fresh->provider)->toBe('openai')
        ->and($fresh->model)->toBe('gpt-5.4-nano')
        ->and($fresh->reasoning)->toBe(AiReasoningLevel::Low);
});

test('task and scope are unique together', function (): void {
    AiTaskModel::factory()->task(AiTask::Decision)->event('sonarr:Download')->create();

    AiTaskModel::factory()->task(AiTask::Decision)->event('sonarr:Download')->create();
})->throws(UniqueConstraintViolationException::class);

test('only the decision task accepts event scopes', function (): void {
    expect(array_values(array_filter(AiTask::cases(), fn (AiTask $aiTask): bool => $aiTask->acceptsEventScopes())))
        ->toBe([AiTask::Decision]);
});

test('provider default is not a sendable level and sorts below none', function (): void {
    expect(AiReasoningLevel::ProviderDefault->isSendable())->toBeFalse()
        ->and(AiReasoningLevel::None->isSendable())->toBeTrue()
        ->and(AiReasoningLevel::ProviderDefault->rank())->toBeLessThan(AiReasoningLevel::None->rank())
        ->and(AiReasoningLevel::sendable())->not->toContain(AiReasoningLevel::ProviderDefault)
        ->and(AiReasoningLevel::mapForSelect(labelKey: 'label')[0])
        ->toBe(['label' => 'Provider default', 'value' => 'provider_default']);
});

test('forTask scopes rows to one task', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->create();
    AiTaskModel::factory()->task(AiTask::Title)->create();

    expect(AiTaskModel::query()->forTask(AiTask::Title)->count())->toBe(1);
});

test('a task scope can hold several tiers ordered by position', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->conditions(20)->create();
    AiTaskModel::factory()->task(AiTask::Chat)->position(1)->selecting('openai', 'gpt-5-nano')->create();

    expect(AiTaskModel::query()->forTask(AiTask::Chat)->orderBy('position')->pluck('model')->all())
        ->toBe(['gpt-5.6-luna', 'gpt-5-nano']);
});

test('two tiers cannot share a position', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->create();
    AiTaskModel::factory()->task(AiTask::Chat)->create();
})->throws(UniqueConstraintViolationException::class);

test('a tier has conditions when either minimum is set', function (?int $percent, ?int $tokens, bool $expected): void {
    $aiTaskModel = AiTaskModel::factory()->conditions($percent, $tokens)->make();

    expect($aiTaskModel->hasConditions())->toBe($expected)
        ->and($aiTaskModel->min_pool_percent)->toBe($percent)
        ->and($aiTaskModel->min_pool_tokens)->toBe($tokens);
})->with([
    'none' => [null, null, false],
    'percent' => [20, null, true],
    'tokens' => [null, 50_000, true],
    'both' => [20, 50_000, true],
]);
