<?php

declare(strict_types=1);

namespace App\Services\AiBudget;

use App\Ai\TaskModelResolver;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use App\Services\AiUsage\ModelPriceLookup;
use App\Settings\AiSettings;

/**
 * The hard budget sums catalog prices; a model without a price row costs 0,
 * so its usage never moves spend toward the cap. Deliberately not a block:
 * this only lists the currently selected models an admin should price.
 */
final readonly class UnpricedModelDetector
{
    public function __construct(
        private AiSettings $aiSettings,
        private TaskModelResolver $taskModelResolver,
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

        $selections = [];

        foreach (AiTask::cases() as $aiTask) {
            if ($aiTask !== AiTask::Failover) {
                $selections[$aiTask->label()] = $this->taskModelResolver->resolve($aiTask)->modelSelection();
            }
        }

        foreach ($this->taskModelResolver->rows() as $aiTaskModel) {
            if ($aiTaskModel->task === AiTask::Decision && $aiTaskModel->scope !== AiTaskModel::DEFAULT_SCOPE && filled($aiTaskModel->model)) {
                $selections[sprintf('%s (%s)', AiTask::Decision->label(), $aiTaskModel->scope)] = $this->taskModelResolver->resolve(AiTask::Decision, $aiTaskModel->scope)->modelSelection();
            }
        }

        $unpriced = [];

        foreach ($selections as $role => $modelSelection) {
            if ($this->modelPriceLookup->snapshotFor($modelSelection->provider, $modelSelection->model, false) === null) {
                $unpriced[] = ['role' => $role, 'provider' => $modelSelection->provider, 'model' => $modelSelection->model];
            }
        }

        return $unpriced;
    }
}
