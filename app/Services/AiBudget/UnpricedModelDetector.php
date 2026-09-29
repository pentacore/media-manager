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

        $selections = [
            'Chat' => $this->aiSettings->chatSelection(),
            'Decision agent' => $this->decisionAgentSettings->selection(),
            'Sub-agents' => $this->aiSettings->subAgentSelection(),
            'Chat titles' => $this->aiSettings->titleSelection(),
            'Price updater' => $this->aiSettings->priceUpdaterSelection(),
        ];

        $unpriced = [];

        foreach ($selections as $role => $modelSelection) {
            if ($this->modelPriceLookup->snapshotFor($modelSelection->provider, $modelSelection->model, false) === null) {
                $unpriced[] = ['role' => $role, 'provider' => $modelSelection->provider, 'model' => $modelSelection->model];
            }
        }

        return $unpriced;
    }
}
