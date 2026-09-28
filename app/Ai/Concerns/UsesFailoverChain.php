<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use App\Settings\AiSettings;

/**
 * Gives an agent the configured primary-then-failover provider chain.
 *
 * laravel/ai's Promptable::getProvidersAndModels() calls an agent's
 * provider() whenever prompt()/stream() receive no provider argument, so every
 * caller — including AgentTool, which runs a sub-agent with no provider at
 * all — fails over without passing one. The chain keeps this agent's own
 * model() on the primary and lets the failover provider use its own default
 * model (see AiSettings::providerChainWithModel()). Null means no failover is
 * configured, and the SDK falls back to `ai.default` plus model().
 */
trait UsesFailoverChain
{
    /**
     * @return array<string, string|null>|null
     */
    public function provider(): ?array
    {
        return resolve(AiSettings::class)->providerChainWithModel($this->model());
    }
}
