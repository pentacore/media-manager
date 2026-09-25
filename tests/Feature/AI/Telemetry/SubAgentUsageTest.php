<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Telemetry;

use App\Models\AiUsageRecord;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;

class BillingChildFixtureAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'child';
    }
}

class BillingParentFixtureAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'parent';
    }

    public function tools(): iterable
    {
        return [new BillingChildFixtureAgent];
    }
}

test('sdk behaviour: parent usage includes sub-agent usage', function (): void {
    BillingChildFixtureAgent::fake([new TextResponse('child done', new TextUsage(100, 10), new Meta('openai', 'gpt-5-mini'))]);
    BillingParentFixtureAgent::fake([
        new ToolCall(id: 'c1', name: 'BillingChildFixtureAgent', arguments: ['task' => 'look']),
        new TextResponse('parent done', new TextUsage(1000, 50), new Meta('openai', 'gpt-5-mini')),
    ]);

    $response = (new BillingParentFixtureAgent)->prompt('go');

    // 1.0 does not fold sub-agent usage into the parent response, so each
    // row bills only its own run. A future SDK change here breaks loudly.
    expect($response->usage->inputTokens)->toBe(1000);
})->group('characterization');

test('parent and child are each billed once and linked', function (): void {
    BillingChildFixtureAgent::fake([new TextResponse('child done', new TextUsage(100, 10), new Meta('openai', 'gpt-5-mini'))]);
    BillingParentFixtureAgent::fake([
        new ToolCall(id: 'c1', name: 'BillingChildFixtureAgent', arguments: ['task' => 'look']),
        new TextResponse('parent done', new TextUsage(1000, 50), new Meta('openai', 'gpt-5-mini')),
    ]);

    $response = (new BillingParentFixtureAgent)->prompt('go');

    $parent = AiUsageRecord::where('invocation_id', $response->invocationId)->sole();
    $child = AiUsageRecord::where('parent_invocation_id', $response->invocationId)->sole();

    expect($child->agent_class)->toBe(BillingChildFixtureAgent::class)
        ->and($child->prompt_tokens)->toBe(100)
        ->and($parent->prompt_tokens)->toBe(1000)
        ->and(AiUsageRecord::sum('prompt_tokens'))->toBe(1100);
});
