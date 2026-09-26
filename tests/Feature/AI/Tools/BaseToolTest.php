<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ActionRequestStatus;
use App\Enums\AiMode;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Services\Actions\ActionDescription;
use App\Settings\AiSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

function makeFakeRequest(array $args = []): Request
{
    return new Request($args);
}

class FakeReadTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'fake';
    }

    /**
     * @return array{}
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<string, bool|string>
     */
    protected function execute(Request $request): array
    {
        return ['ok' => true, 'data' => 'hello'];
    }
}

class FakeThrowingTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'fake';
    }

    /**
     * @return array{}
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    protected function execute(Request $request): array
    {
        throw new RuntimeException('boom');
    }
}

class FakeBinaryTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'fake';
    }

    /**
     * @return array{}
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<string, string>
     */
    protected function execute(Request $request): array
    {
        return ['title' => "\xC3\x28"]; // invalid UTF-8 sequence
    }
}

class FakeValidatingTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'fake';
    }

    /**
     * @return array{}
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array{limit: int}
     */
    protected function execute(Request $request): array
    {
        $validated = $request->validate(['limit' => ['required', 'integer', 'between:1,10']]);

        return ['limit' => (int) $validated['limit']];
    }
}

class FakeDestructiveTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'fake';
    }

    /**
     * @return array{}
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function risk(): Risk
    {
        return Risk::Destructive;
    }

    /**
     * @return array<string, array<string, int>|string>
     */
    protected function execute(Request $request): array
    {
        return ['type' => 'delete_series', 'target_service' => 'sonarr', 'payload' => ['sonarr_series_id' => 42]];
    }
}

class FakeUndescribableDestructiveTool extends FakeDestructiveTool
{
    /**
     * @return array<string, array<never, never>|string>
     */
    #[Override]
    protected function execute(Request $request): array
    {
        return ['type' => 'delete_series', 'target_service' => 'sonarr', 'payload' => []];
    }
}

class FakeSelfDescribedDestructiveTool extends FakeDestructiveTool
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function execute(Request $request): array
    {
        return [
            'type' => 'replace_media_file',
            'target_service' => 'sonarr',
            'payload' => [],
            'description' => new ActionDescription('Replace the file', 'Sonarr will grab a better release.'),
            'origin' => 'system',
        ];
    }
}

class FakeUnsupportedTypeDestructiveTool extends FakeDestructiveTool
{
    /**
     * @return array<string, array<never, never>|string>
     */
    #[Override]
    protected function execute(Request $request): array
    {
        return ['type' => 'replace_media_file', 'target_service' => 'sonarr', 'payload' => []];
    }
}

class FakeHookedDestructiveTool extends FakeDestructiveTool
{
    public ?int $queuedActionRequestId = null;

    #[Override]
    protected function actionRequestQueued(ActionRequest $actionRequest, array $candidate): void
    {
        $this->queuedActionRequestId = $actionRequest->id;
    }
}

test('Read tool returns json-encoded execute() result', function (): void {
    $tool = new FakeReadTool;

    $result = $tool->handle(makeFakeRequest());

    expect(json_decode($result, true))->toBe(['ok' => true, 'data' => 'hello']);
});

test('handle catches throwables and returns a structured error JSON', function (): void {
    $tool = new FakeThrowingTool;

    $result = $tool->handle(makeFakeRequest());

    $decoded = json_decode($result, true);
    expect($decoded['error'])->toBe('tool_failed');
    expect($decoded['code'])->toBe('runtime_exception');
    expect($decoded['message'])->toContain('try again');
});

test('Destructive tool routes through ActionOrchestrator', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'delete_series',
        'is_enabled' => true,
        'requires_approval' => true,
    ]);

    $tool = new FakeDestructiveTool;
    $result = $tool->handle(makeFakeRequest());

    $decoded = json_decode($result, true);
    expect($decoded['queued'])->toBeTrue();
    expect($decoded['action_request_id'])->toBeInt();
    expect($decoded['status'])->toBe(ActionRequestStatus::Pending->value);

    expect(ActionRequest::where('type', 'delete_series')->count())->toBe(1);
});

test('Destructive tool invokes its scoped post-queue hook', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'delete_series',
        'is_enabled' => true,
        'requires_approval' => true,
    ]);

    $tool = new FakeHookedDestructiveTool;
    $result = json_decode($tool->handle(makeFakeRequest()), true);

    expect($tool->queuedActionRequestId)->toBe($result['action_request_id'])
        ->and(ActionRequest::query()->find($tool->queuedActionRequestId))->not->toBeNull();
});

test('Destructive tool refuses to run in Advisory mode', function (): void {
    resolve(AiSettings::class)->setMode(AiMode::Advisory);

    $tool = new FakeDestructiveTool;
    $result = $tool->handle(makeFakeRequest());

    $decoded = json_decode($result, true);
    expect($decoded['error'])->toBe('advisory_mode_blocks_destructive');
    expect(ActionRequest::count())->toBe(0);
});

test('Destructive tool returns no_action_type_config when type is unknown', function (): void {
    $tool = new FakeDestructiveTool;

    $result = $tool->handle(makeFakeRequest());

    $decoded = json_decode($result, true);
    expect($decoded['queued'])->toBeFalse();
    expect($decoded['reason'])->toBe('no_action_type_config');
});

test('a destructive tool whose target cannot be described is not queued', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'is_enabled' => true, 'requires_approval' => true]);

    $result = json_decode((new FakeUndescribableDestructiveTool)->handle(makeFakeRequest()), true);

    expect($result['queued'])->toBeFalse()
        ->and($result['reason'])->toBe('undescribable_action')
        ->and($result['message'])->toContain('sonarr_series_id')
        ->and(ActionRequest::count())->toBe(0);
});

test('a destructive tool that supplies its own description and origin is queued with them as-is', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'replace_media_file', 'is_enabled' => true, 'requires_approval' => true]);

    (new FakeSelfDescribedDestructiveTool)->handle(makeFakeRequest());

    $actionRequest = ActionRequest::firstWhere('type', 'replace_media_file');
    expect($actionRequest->title)->toBe('Replace the file')
        ->and($actionRequest->description)->toBe('Sonarr will grab a better release.')
        ->and($actionRequest->origin)->toBe('system');
});

test('a destructive tool of a type the describer does not know is not queued', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'replace_media_file', 'is_enabled' => true, 'requires_approval' => true]);

    $result = json_decode((new FakeUnsupportedTypeDestructiveTool)->handle(makeFakeRequest()), true);

    expect($result['queued'])->toBeFalse()
        ->and($result['reason'])->toBe('undescribable_action')
        ->and($result['message'])->toContain('replace_media_file')
        ->and(ActionRequest::count())->toBe(0);
});

test('handle returns valid JSON even when execute() result has invalid UTF-8', function (): void {
    $result = (new FakeBinaryTool)->handle(makeFakeRequest());

    $decoded = json_decode($result, true);
    expect($decoded)->not->toBeNull();
    expect($decoded)->toBeArray();
    // Either the partial-output flag salvaged it (title key present, possibly null),
    // or our error envelope kicked in (error key present).
    expect(array_key_exists('title', $decoded) || array_key_exists('error', $decoded))->toBeTrue();
});

test('a validation failure inside execute returns an invalid_arguments envelope', function (): void {
    $result = json_decode((new FakeValidatingTool)->handle(makeFakeRequest(['limit' => 50])), true);

    expect($result['error'])->toBe('invalid_arguments')
        ->and($result['errors'])->toHaveKey('limit')
        ->and($result['message'])->toContain('call the tool again');
});

test('arguments that pass validation reach the tool result unchanged', function (): void {
    $result = json_decode((new FakeValidatingTool)->handle(makeFakeRequest(['limit' => 3])), true);

    expect($result)->toBe(['limit' => 3]);
});
