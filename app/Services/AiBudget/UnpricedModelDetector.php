<?php

declare(strict_types=1);

namespace App\Services\AiBudget;

use App\Ai\ModelSelection;
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

        foreach (AiTask::cases() as $aiTask) {
            if ($aiTask === AiTask::Failover) {
                continue;
            }

            $scopes = $this->taskModelResolver->rows()
                ->filter(static fn (AiTaskModel $aiTaskModel): bool => $aiTaskModel->task === $aiTask)
                ->pluck('scope')
                ->push(AiTaskModel::DEFAULT_SCOPE)
                ->unique();

            foreach ($scopes as $scope) {
                $configuredSelections = $this->taskModelResolver->configuredSelections($aiTask, $scope);
                $isDefault = $scope === AiTaskModel::DEFAULT_SCOPE;

                if (! $isDefault && $configuredSelections === []) {
                    continue;
                }

                $role = $isDefault ? $aiTask->label() : sprintf('%s (%s)', $aiTask->label(), $scope);
                $live = $this->taskModelResolver->resolve($aiTask, $isDefault ? null : $scope)->modelSelection();
                $selections[$role] = $live;

                foreach ($configuredSelections as $configuredSelection) {
                    if ($configuredSelection['provider'] === $live->provider && $configuredSelection['model'] === $live->model) {
                        continue;
                    }

                    $selections[$this->tierRole($aiTask, $scope, $configuredSelection['position'])] = new ModelSelection($configuredSelection['provider'], $configuredSelection['model']);
                }
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

    private function tierRole(AiTask $aiTask, string $scope, int $position): string
    {
        return $scope === AiTaskModel::DEFAULT_SCOPE
            ? sprintf('%s (tier %d)', $aiTask->label(), $position)
            : sprintf('%s (%s, tier %d)', $aiTask->label(), $scope, $position);
    }
}
