<?php

declare(strict_types=1);

namespace App\Ai;

use App\Settings\AiSettings;
use Laravel\Ai\Ai;
use Throwable;

/**
 * Whether the providers an agent run may reach support a capability.
 * laravel/ai silently drops any provider tool (CodeExecution, WebFetch, …)
 * the reached provider lacks, so such a tool is only registered when the
 * whole chain supports it — a failover must not quietly lose a tool the
 * agent's instructions promise. A ToolSearch is different: the SDK inlines
 * its tools on a provider without tool search, so the primary alone decides.
 */
final readonly class ProviderCapabilities
{
    public function __construct(private AiSettings $aiSettings) {}

    /**
     * The providers an agent on this selection may reach, in failover order.
     *
     * @return list<string>
     */
    public function chain(ModelSelection $modelSelection): array
    {
        return array_keys($this->aiSettings->providerChainFor($modelSelection));
    }

    /**
     * Whether every provider an agent on this selection may reach implements
     * the given contract. A provider that cannot be resolved counts as
     * unsupported.
     *
     * @param  class-string  $contract  a `Laravel\Ai\Contracts\Providers\*` interface
     */
    public function everyProviderSupports(string $contract, ModelSelection $modelSelection): bool
    {
        foreach ($this->chain($modelSelection) as $provider) {
            if (! $this->supports($provider, $contract)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the first provider in the chain implements the contract. Use
     * this only for a capability whose loss on a failover is harmless: a
     * ToolSearch, whose deferred tools laravel/ai inlines on a provider
     * without tool search (TextGenerationLoop::toolsSupportedBy), so the
     * fallback still gets every tool, just in a larger payload.
     *
     * @param  class-string  $contract  a `Laravel\Ai\Contracts\Providers\*` interface
     */
    public function primarySupports(string $contract, ModelSelection $modelSelection): bool
    {
        $primary = $this->chain($modelSelection)[0] ?? null;

        return $primary !== null && $this->supports($primary, $contract);
    }

    /**
     * @param  class-string  $contract
     */
    private function supports(string $provider, string $contract): bool
    {
        try {
            return Ai::textProvider($provider) instanceof $contract;
        } catch (Throwable) {
            return false;
        }
    }
}
