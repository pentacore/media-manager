<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\TaskModelResolver;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Enums\Lab;

beforeEach(function (): void {
    Cache::flush();
    config()->set('ai.default', 'openai');
});

test('the failover provider reads back from its AI Models row', function (): void {
    $taskModelResolver = resolve(TaskModelResolver::class);

    expect($taskModelResolver->failover())->toBeNull();

    $failover = AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => Lab::Anthropic->value])->create();
    expect($taskModelResolver->failover())->toBe(['provider' => Lab::Anthropic->value, 'model' => null]);

    $failover->delete();
    expect($taskModelResolver->failover())->toBeNull();
});

test('an agent on the failover path resolves without exception', function (): void {
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => Lab::Anthropic->value])->create();

    MediaAgent::fake(['ok']);

    $agentResponse = (new MediaAgent)->prompt('hello');

    expect($agentResponse->text)->toBe('ok');

    MediaAgent::assertPrompted('hello');
});
