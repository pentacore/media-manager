<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ActionRequestStatus;
use App\Enums\AiMode;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
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

test('handle returns valid JSON even when execute() result has invalid UTF-8', function (): void {
    $result = (new FakeBinaryTool)->handle(makeFakeRequest());

    $decoded = json_decode($result, true);
    expect($decoded)->not->toBeNull();
    expect($decoded)->toBeArray();
    // Either the partial-output flag salvaged it (title key present, possibly null),
    // or our error envelope kicked in (error key present).
    expect(array_key_exists('title', $decoded) || array_key_exists('error', $decoded))->toBeTrue();
});
