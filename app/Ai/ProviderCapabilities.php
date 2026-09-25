<?php

declare(strict_types=1);

namespace App\Ai;

use App\Settings\AiSettings;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
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
     * The primary provider followed by the failover provider, if any.
     *
     * @return list<Lab>
     */
    public function chain(): array
    {
        return array_values($this->aiSettings->providerChain() ?? [$this->aiSettings->primaryProvider()]);
    }

    /**
     * Whether every provider in the chain implements the given contract. A
     * provider that cannot be resolved counts as unsupported.
     *
     * @param  class-string  $contract  a `Laravel\Ai\Contracts\Providers\*` interface
     */
    public function everyProviderSupports(string $contract): bool
    {
        foreach ($this->chain() as $lab) {
            try {
                if (! Ai::textProvider($lab->value) instanceof $contract) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }
}
