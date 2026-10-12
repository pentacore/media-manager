<?php

declare(strict_types=1);

namespace App\Ai;

use App\Settings\AiSettings;
use Laravel\Ai\Ai;
use Throwable;

/**
 * Whether every provider an agent run may reach supports a capability.
 * laravel/ai inlines a ToolSearch's tools and silently drops any provider
 * tool the reached provider lacks, so a capability is only registered when
 * the whole chain supports it — a failover must not quietly lose a tool the
 * agent's instructions promise.
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
            try {
                if (! Ai::textProvider($provider) instanceof $contract) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }
}
