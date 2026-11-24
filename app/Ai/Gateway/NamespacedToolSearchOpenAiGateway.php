<?php

declare(strict_types=1);

namespace App\Ai\Gateway;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Override;

/**
 * OpenAI answers the request after a deferred tool's call (previous_response_id
 * plus function_call_output) with a 500 server_error when the deferred tools are
 * sent loose next to `tool_search`, which failed every chat turn that searched
 * for a tool. Grouped in a `namespace`, as OpenAI's tool search guide does,
 * the continuation passes and the model still calls the tools by bare name.
 *
 * Mirrors laravel/ai PR #1259; drop this class and its registration in
 * AIServiceProvider once a laravel/ai release ships that change.
 *
 * @see https://github.com/laravel/ai/issues/1255
 * @see https://github.com/laravel/ai/pull/1259
 */
final class NamespacedToolSearchOpenAiGateway extends OpenAiGateway
{
    /**
     * @param  array<int, mixed>  $tools
     * @return array<int, array<string, mixed>>
     */
    #[Override]
    protected function mapTools(array $tools, Provider $provider, bool $stateless = false): array
    {
        $mapped = [];
        $namespaces = 0;

        foreach ($tools as $tool) {
            if ($tool instanceof ToolSearch) {
                $this->guardStatelessToolSearch($provider, $stateless);

                if (blank($tool->tools)) {
                    continue;
                }

                $namespaces++;

                $mapped[] = [
                    'type' => 'namespace',
                    'name' => $namespaces === 1 ? 'deferred_tools' : 'deferred_tools_'.$namespaces,
                    'description' => 'Tools loaded on demand.',
                    'tools' => array_map(fn (Tool $deferred): array => $this->mapTool($deferred, defer: true), $tool->tools),
                ];

                $mapped[] = [
                    'type' => 'tool_search',
                    ...array_diff_key($tool->providerOptions(Lab::tryFrom($provider->driver()) ?? $provider->driver()), ['type' => true]),
                ];
            } elseif ($tool instanceof ProviderTool) {
                $mapped[] = $this->mapProviderTool($tool, $provider);
            } elseif ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }
}
