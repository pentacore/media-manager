<?php

declare(strict_types=1);

namespace App\Ai\Routing;

use App\Ai\ProviderCapabilities;
use App\Settings\AiSettings;
use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Providers\Tools\ToolSearch;

/**
 * Hosted tool search keeps rarely-needed tool definitions out of every
 * request. It is gated on the chain's primary provider only: on a failover
 * to a provider without tool search, laravel/ai (1.4.1,
 * TextGenerationLoop::toolsSupportedBy) inlines every deferred tool, so the
 * fallback loses no tool, it only sends the larger payload.
 */
final readonly class ToolPayload
{
    public function __construct(
        private ProviderCapabilities $providerCapabilities,
        private AiSettings $aiSettings,
    ) {}

    /**
     * Core tools stay first-class; every other tool is deferred behind one
     * ToolSearch when the primary provider supports it. Otherwise the tools
     * pass through.
     *
     * @template TTool of object
     *
     * @param  array<int, TTool>  $tools
     * @return array<int, TTool|ToolSearch>
     */
    public function build(array $tools): array
    {
        if (! $this->providerCapabilities->primarySupports(SupportsToolSearch::class, $this->aiSettings->chatSelection())) {
            return $tools;
        }

        $core = array_values(array_filter($tools, static fn (object $tool): bool => in_array($tool::class, ToolGroup::core(), true)));
        $deferred = array_values(array_filter($tools, static fn (object $tool): bool => ! in_array($tool::class, ToolGroup::core(), true)));

        if ($deferred === []) {
            return $core;
        }

        // The SDK documents array<Tool>, but sub-agents (CanActAsTool) are deferred here too and ToolSearch handles them.
        // @phpstan-ignore argument.type
        return [...$core, new ToolSearch($deferred)];
    }
}
