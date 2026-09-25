<?php

declare(strict_types=1);

namespace App\Ai\Routing;

use App\Ai\ProviderCapabilities;
use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Providers\Tools\ToolSearch;

/**
 * Hosted tool search keeps rarely-needed tool definitions out of every
 * request — but only when the whole failover chain supports it, because the
 * SDK throws a non-failoverable LogicException otherwise.
 */
final readonly class ToolPayload
{
    public function __construct(private ProviderCapabilities $providerCapabilities) {}

    /**
     * Core tools stay first-class; every other tool is deferred behind one
     * ToolSearch when the chain supports it. Otherwise the tools pass through.
     *
     * @param  array<int, object>  $tools
     * @return array<int, object>
     */
    public function build(array $tools): array
    {
        if (! $this->providerCapabilities->everyProviderSupports(SupportsToolSearch::class)) {
            return $tools;
        }

        $core = array_values(array_filter($tools, static fn (object $tool): bool => in_array($tool::class, ToolGroup::core(), true)));
        $deferred = array_values(array_filter($tools, static fn (object $tool): bool => ! in_array($tool::class, ToolGroup::core(), true)));

        return $deferred === [] ? $core : [...$core, new ToolSearch($deferred)];
    }
}
