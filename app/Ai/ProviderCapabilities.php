<?php

declare(strict_types=1);

namespace App\Ai;

use App\Settings\AiSettings;
use Laravel\Ai\Ai;
use Throwable;

/**
 * Whether every provider an agent run may reach supports a capability.
 * laravel/ai checks ToolSearch (and provider tools) against the provider
 * before middleware and throws a non-failoverable LogicException, so a
 * capability is only safe to register when the whole chain supports it.
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
