<?php

declare(strict_types=1);

use App\Ai\Decision\DecisionRunContext;
use App\Ai\Tools\BaseTool;
use App\Ai\Tools\Decision\DecisionTool;
use App\Ai\Tools\Decision\InspectStuckImportTool;
use App\Ai\Tools\Decision\ProposeActionTool;
use App\Ai\Tools\Decision\RemoveStuckDownloadTool;
use App\Ai\Tools\Decision\ResolveManualImportTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

afterEach(function (): void {
    app()->forgetInstance(DecisionRunContext::class);
});

/**
 * A minimal decision tool: validates one argument and echoes it back.
 */
function decisionToolProbe(): DecisionTool
{
    return new class extends DecisionTool
    {
        public function description(): string
        {
            return 'Probe.';
        }

        /**
         * @return array<string, mixed>
         */
        public function schema(JsonSchema $schema): array
        {
            return [];
        }

        /**
         * @return array<string, mixed>
         */
        protected function execute(Request $request): array
        {
            $validated = $request->validate(['service' => ['required', 'string', 'regex:/^(sonarr|radarr)$/Di']]);

            return ['queued' => true, 'service' => mb_strtolower((string) $validated['service'])];
        }
    };
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function decisionToolResult(DecisionTool $decisionTool, array $arguments): array
{
    return json_decode((string) $decisionTool->handle(new Request($arguments)), true);
}

test('a decision tool refuses to run outside a decision run', function (): void {
    expect(decisionToolResult(decisionToolProbe(), ['service' => 'sonarr']))
        ->toBe(['queued' => false, 'reason' => 'no_active_run']);
});

test('a decision tool stops once the run reached its action cap', function (): void {
    $decisionRunContext = new DecisionRunContext(null, 1, 'sonarr');
    $decisionRunContext->recordQueued(1, true);
    app()->instance(DecisionRunContext::class, $decisionRunContext);

    expect(decisionToolResult(decisionToolProbe(), ['service' => 'sonarr']))
        ->toBe(['queued' => false, 'reason' => 'max_actions_reached']);
});

test('invalid arguments come back as an invalid_arguments rejection naming the field', function (): void {
    app()->instance(DecisionRunContext::class, new DecisionRunContext(null, 3, 'sonarr'));

    $result = decisionToolResult(decisionToolProbe(), ['service' => 'plex']);

    expect($result['queued'])->toBeFalse()
        ->and($result['reason'])->toBe('invalid_arguments')
        ->and($result['errors'])->toHaveKey('service')
        ->and($result['message'])->toBe('Some arguments were invalid. Fix them and call the tool again.');
});

test('service names are accepted in any case', function (): void {
    app()->instance(DecisionRunContext::class, new DecisionRunContext(null, 3, 'sonarr'));

    expect(decisionToolResult(decisionToolProbe(), ['service' => 'Sonarr']))
        ->toBe(['queued' => true, 'service' => 'sonarr']);
});

test('every decision tool extends DecisionTool and none extends BaseTool', function (string $toolClass): void {
    expect(is_subclass_of($toolClass, DecisionTool::class))->toBeTrue()
        ->and(is_subclass_of($toolClass, BaseTool::class))->toBeFalse();
})->with([
    ProposeActionTool::class,
    RemoveStuckDownloadTool::class,
    ResolveManualImportTool::class,
    InspectStuckImportTool::class,
]);
