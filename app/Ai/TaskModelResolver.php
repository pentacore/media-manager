<?php

declare(strict_types=1);

namespace App\Ai;

use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use LogicException;

/**
 * Resolves the provider, model and reasoning level each AI task runs on.
 * Null always means inherit; the provider and model resolve as a pair,
 * reasoning on its own.
 *
 * Inheritance (first match wins; the provider and model resolve as a pair):
 *
 * - Chat: model is the ChatTurnContext pair, then the chat row, then config
 *   `mediamanager.ai.model` on `config('ai.default')`. Reasoning is the
 *   ChatTurnContext, then the chat row, then config
 *   `mediamanager.ai.advisor_reasoning_level`, then ProviderDefault.
 * - FileInspector and StuckDownloadInvestigator: model is the own row, then
 *   config `mediamanager.ai.sub_agent_model`, then the admin chat default
 *   (row, then config; never the ChatTurnContext model). Reasoning is the
 *   ChatTurnContext, then the own row, then ProviderDefault.
 * - Decision: model is the event row, then the decision row, then config
 *   `mediamanager.decision_agent.model`, then the chat row/config pair.
 *   Reasoning is the event row, then the decision row, then config
 *   `mediamanager.decision_agent.reasoning_level`, then ProviderDefault.
 * - Title: model is the own row, then config `mediamanager.ai.title_model`
 *   (`auto` becomes `Ai::textProvider($provider)->cheapestTextModel()`, or
 *   the config title model when that provider cannot be resolved).
 *   Reasoning is the own row, then ProviderDefault.
 * - PriceUpdater: model is the own row, then config
 *   `mediamanager.ai.pricing.updater_model`, then the chat row/config pair.
 *   Reasoning is the own row, then ProviderDefault.
 *
 * Rows are memoised for the request (the class is scoped) and flushed by
 * AiTaskModelObserver whenever a row is saved or deleted.
 */
final class TaskModelResolver
{
    /** @var Collection<int, AiTaskModel>|null */
    private ?Collection $rows = null;

    public function __construct(private readonly ChatTurnContext $chatTurnContext) {}

    public function resolve(AiTask $aiTask, ?string $eventKey = null): ResolvedSelection
    {
        throw_if($aiTask === AiTask::Failover, InvalidArgumentException::class, 'Resolve the failover task with failover().');

        [$provider, $model] = $this->pairFor($aiTask, $eventKey);

        return new ResolvedSelection($provider, $model, $this->reasoningFor($aiTask, $eventKey));
    }

    /**
     * The failover provider and its optional model, or null when failover is off.
     *
     * @return array{provider: string, model: string|null}|null
     */
    public function failover(): ?array
    {
        $row = $this->row(AiTask::Failover);

        if (blank($row?->provider)) {
            return null;
        }

        return ['provider' => (string) $row->provider, 'model' => filled($row->model) ? $row->model : null];
    }

    public function row(AiTask $aiTask, string $scope = AiTaskModel::DEFAULT_SCOPE): ?AiTaskModel
    {
        return $this->rows()->first(
            static fn (AiTaskModel $aiTaskModel): bool => $aiTaskModel->task === $aiTask && $aiTaskModel->scope === $scope,
        );
    }

    /**
     * @return Collection<int, AiTaskModel>
     */
    public function rows(): Collection
    {
        return $this->rows ??= AiTaskModel::query()->get();
    }

    public function flush(): void
    {
        $this->rows = null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function pairFor(AiTask $aiTask, ?string $eventKey): array
    {
        return match ($aiTask) {
            AiTask::Chat => $this->chatTurnContext->hasModel()
                ? [(string) $this->chatTurnContext->provider, (string) $this->chatTurnContext->model]
                : $this->chatDefaultPair(),
            AiTask::Title => $this->titlePair(),
            AiTask::Decision => ($eventKey !== null ? $this->rowPair(AiTask::Decision, $eventKey) : null)
                ?? $this->rowPair(AiTask::Decision)
                ?? $this->configPair('mediamanager.decision_agent.model')
                ?? $this->chatDefaultPair(),
            AiTask::PriceUpdater => $this->rowPair(AiTask::PriceUpdater)
                ?? $this->configPair('mediamanager.ai.pricing.updater_model')
                ?? $this->chatDefaultPair(),
            AiTask::FileInspector, AiTask::StuckDownloadInvestigator => $this->rowPair($aiTask)
                ?? $this->configPair('mediamanager.ai.sub_agent_model')
                ?? $this->chatDefaultPair(),
            AiTask::Failover => throw new InvalidArgumentException('Resolve the failover task with failover().'),
        };
    }

    private function reasoningFor(AiTask $aiTask, ?string $eventKey): AiReasoningLevel
    {
        $conversation = in_array($aiTask, [AiTask::Chat, AiTask::FileInspector, AiTask::StuckDownloadInvestigator], true)
            ? $this->chatTurnContext->reasoning
            : null;

        return $conversation
            ?? ($eventKey !== null ? $this->row($aiTask, $eventKey)?->reasoning : null)
            ?? $this->row($aiTask)->reasoning
            ?? $this->configLevel($aiTask)
            ?? AiReasoningLevel::ProviderDefault;
    }

    /**
     * The admin chat default (row, then config), ignoring any conversation
     * override: tasks that follow "the chat model" follow the admin choice.
     *
     * @return array{0: string, 1: string}
     */
    private function chatDefaultPair(): array
    {
        return $this->rowPair(AiTask::Chat)
            ?? $this->configPair('mediamanager.ai.model')
            ?? [$this->defaultProvider(), 'gpt-5-mini'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function titlePair(): array
    {
        [$provider, $model] = $this->rowPair(AiTask::Title)
            ?? $this->configPair('mediamanager.ai.title_model')
            ?? [$this->defaultProvider(), 'gpt-5.4-nano'];

        if ($model === 'auto') {
            try {
                $model = Ai::textProvider($provider)->cheapestTextModel();
            } catch (InvalidArgumentException|LogicException) {
                $model = $this->configTitleModel();
            }
        }

        return [$provider, $model];
    }

    /**
     * The configured title model, used when `auto` cannot be resolved on the
     * title provider; never the `auto` sentinel itself.
     */
    private function configTitleModel(): string
    {
        $model = trim((string) config('mediamanager.ai.title_model', ''));

        return $model === '' || $model === 'auto' ? 'gpt-5.4-nano' : $model;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function rowPair(AiTask $aiTask, string $scope = AiTaskModel::DEFAULT_SCOPE): ?array
    {
        $row = $this->row($aiTask, $scope);

        if (blank($row?->model)) {
            return null;
        }

        return [filled($row->provider) ? (string) $row->provider : $this->defaultProvider(), (string) $row->model];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function configPair(string $key): ?array
    {
        $model = trim((string) config($key, ''));

        return $model === '' ? null : [$this->defaultProvider(), $model];
    }

    private function configLevel(AiTask $aiTask): ?AiReasoningLevel
    {
        $key = match ($aiTask) {
            AiTask::Chat => 'mediamanager.ai.advisor_reasoning_level',
            AiTask::Decision => 'mediamanager.decision_agent.reasoning_level',
            default => null,
        };

        return $key === null ? null : AiReasoningLevel::tryFrom((string) config($key, ''));
    }

    private function defaultProvider(): string
    {
        return (string) config('ai.default', 'openai');
    }
}
