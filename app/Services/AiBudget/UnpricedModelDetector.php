<?php

declare(strict_types=1);

namespace App\Services\AiBudget;

use App\Services\AiUsage\ModelPriceLookup;
use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;

/**
 * The hard budget sums catalog prices; a model without a price row costs 0,
 * so its usage never moves spend toward the cap. Deliberately not a block:
 * this only lists the currently selected models an admin should price.
 */
final readonly class UnpricedModelDetector
{
    public function __construct(
        private AiSettings $aiSettings,
        private DecisionAgentSettings $decisionAgentSettings,
        private ModelPriceLookup $modelPriceLookup,
    ) {}

    /**
     * @return list<array{role: string, provider: string, model: string}>
     */
    public function forHardCap(): array
    {
        if ($this->aiSettings->hardBudgetUsd() === null) {
            return [];
        }

        $provider = $this->aiSettings->primaryProvider()->value;
        $selectedModels = [
            'Chat' => $this->aiSettings->model(),
            'Decision agent' => $this->decisionAgentSettings->model(),
            'Sub-agents' => $this->aiSettings->subAgentModel(),
            'Chat titles' => $this->aiSettings->titleModel(),
        ];

        $unpriced = [];

        foreach ($selectedModels as $role => $model) {
            if ($this->modelPriceLookup->snapshotFor($provider, $model, false) === null) {
                $unpriced[] = ['role' => $role, 'provider' => $provider, 'model' => $model];
            }
        }

        return $unpriced;
    }
}
