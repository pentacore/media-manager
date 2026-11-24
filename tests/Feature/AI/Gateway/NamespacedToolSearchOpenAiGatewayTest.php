<?php

declare(strict_types=1);

use App\Ai\Gateway\NamespacedToolSearchOpenAiGateway;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\StringType;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Tools\Request;

function namespacedGatewayTool(string $name): Tool
{
    return new readonly class($name) implements Tool
    {
        public function __construct(private string $name) {}

        public function name(): string
        {
            return $this->name;
        }

        public function description(): string
        {
            return 'Tool '.$this->name;
        }

        public function handle(Request $request): string
        {
            return '';
        }

        /**
         * @return StringType[]
         */
        public function schema(JsonSchema $schema): array
        {
            return ['service' => $schema->string()];
        }
    };
}

/**
 * @param  array<int, mixed>  $tools
 * @return array<int, array<string, mixed>>
 */
function mapNamespacedGatewayTools(array $tools): array
{
    $provider = resolve(AiManager::class)->instance('openai');
    $gateway = new NamespacedToolSearchOpenAiGateway(resolve('events'));

    return (fn (): array => $this->mapTools($tools, $provider))->call($gateway);
}

it('groups each tool search deferred tools in a namespace before the tool_search entry', function (): void {
    $mapped = mapNamespacedGatewayTools([
        namespacedGatewayTool('Core'),
        new ToolSearch([namespacedGatewayTool('GetQueue'), namespacedGatewayTool('GetHistory')]),
    ]);

    expect(array_column($mapped, 'type'))->toBe(['function', 'namespace', 'tool_search'])
        ->and($mapped[0]['name'])->toBe('Core')
        ->and($mapped[0])->not->toHaveKey('defer_loading')
        ->and($mapped[1]['name'])->toBe('deferred_tools')
        ->and(array_column($mapped[1]['tools'], 'name'))->toBe(['GetQueue', 'GetHistory'])
        ->and(array_column($mapped[1]['tools'], 'defer_loading'))->toBe([true, true]);
});

it('gives a second tool search its own namespace name', function (): void {
    $mapped = mapNamespacedGatewayTools([
        new ToolSearch([namespacedGatewayTool('A')]),
        new ToolSearch([namespacedGatewayTool('B')]),
    ]);

    expect(array_column(array_filter($mapped, fn (array $tool): bool => $tool['type'] === 'namespace'), 'name'))
        ->toBe(['deferred_tools', 'deferred_tools_2']);
});

it('skips a tool search with no deferred tools', function (): void {
    expect(mapNamespacedGatewayTools([namespacedGatewayTool('Core'), new ToolSearch([])]))
        ->toHaveCount(1);
});

it('serves the openai driver through the namespaced gateway', function (): void {
    $provider = resolve(AiManager::class)->instance('openai');

    expect($provider)->toBeInstanceOf(OpenAiProvider::class)
        ->and((fn () => $this->gateway)->call($provider))->toBeInstanceOf(NamespacedToolSearchOpenAiGateway::class);
});
