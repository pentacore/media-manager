<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use App\Ai\ModelSelection;
use App\Settings\AiSettings;

/**
 * Gives an agent its own provider => model chain: the provider and model it
 * is configured to run on, then the failover provider (with its optional
 * model) when one is configured.
 *
 * laravel/ai's Promptable::getProvidersAndModels() calls an agent's
 * provider() whenever prompt()/stream() receive no provider argument, so every
 * caller — including AgentTool, which runs a sub-agent with no provider at
 * all — reaches the configured provider and fails over without passing one.
 * The chain is always explicit, so `ai.default` only fills in model settings
 * that were never saved with a provider (see AiSettings::providerChainFor()).
 */
trait UsesFailoverChain
{
    /**
     * The provider + model this agent runs on.
     */
    abstract public function modelSelection(): ModelSelection;

    /**
     * @return array<string, string|null>
     */
    public function provider(): array
    {
        return resolve(AiSettings::class)->providerChainFor($this->modelSelection());
    }
}
