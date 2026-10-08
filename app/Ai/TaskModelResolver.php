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
 *
 * Every task scope (`default`, or a Decision webhook event key) holds an
 * ordered tier list (ai_task_models rows by `position`). The first tier that
 * may run wins: every tier but the last is skipped while its model is
 * rate-limited or, when it has pool minimums, while its model's free pool has
 * less left (TierEligibility). A tier with no model is an "inherit tier"
 * (always last) and hands off to what the scope inherits:
 *
 * - an event scope → the Decision default list;
 * - Chat → config `mediamanager.ai.model` on `config('ai.default')`;
 * - Title → config `mediamanager.ai.title_model` (`auto` becomes the
 *   provider's cheapest text model);
 * - Decision → config `mediamanager.decision_agent.model`, then the Chat list;
 * - PriceUpdater → config `mediamanager.ai.pricing.updater_model`, then the
 *   Chat list;
 * - FileInspector, StuckDownloadInvestigator → config
 *   `mediamanager.ai.sub_agent_model`, then the Chat list.
 *
 * A scope without rows inherits the same way. Following another task's list
 * takes only its provider/model pair (and tier outcome), never its reasoning.
 *
 * Reasoning: the conversation override (Chat and sub-agents), then the
 * picked tier's level (an inherit tier without one keeps what its same-task
 * parent picked), then the task's config level, then ProviderDefault. A
 * conversation model override bypasses tiers; without a conversation
 * reasoning it uses the Chat list's first tier's level.
 *
 * Rows and list walks are memoised for the request or job (the class is
 * scoped) and flushed by AiTaskModelObserver whenever a row is saved or
 * deleted. The memo holds the walk only; ChatTurnContext applies on top at
 * every resolve().
 */
final class TaskModelResolver
{
    /** @var Collection<int, AiTaskModel>|null */
    private ?Collection $rows = null;

    /** @var array<string, TierPick> */
    private array $picks = [];

    public function __construct(
        private readonly ChatTurnContext $chatTurnContext,
        private readonly TierEligibility $tierEligibility,
    ) {}

    public function resolve(AiTask $aiTask, ?string $eventKey = null): ResolvedSelection
    {
        throw_if($aiTask === AiTask::Failover, InvalidArgumentException::class, 'Resolve the failover task with failover().');

        if ($aiTask === AiTask::Chat && $this->chatTurnContext->hasModel()) {
            return new ResolvedSelection(
                (string) $this->chatTurnContext->provider,
                (string) $this->chatTurnContext->model,
                $this->chatTurnContext->reasoning
                    ?? $this->row(AiTask::Chat)?->reasoning
                    ?? $this->configLevel(AiTask::Chat)
                    ?? AiReasoningLevel::ProviderDefault,
            );
        }

        $scope = $aiTask === AiTask::Decision && $eventKey !== null ? $eventKey : AiTaskModel::DEFAULT_SCOPE;
        $tierPick = $this->pick($aiTask, $scope);

        return new ResolvedSelection(
            $tierPick->provider,
            $tierPick->model,
            $this->conversationReasoning($aiTask)
                ?? $tierPick->reasoning
                ?? $this->configLevel($aiTask)
                ?? AiReasoningLevel::ProviderDefault,
            $tierPick->tier,
        );
    }

    /**
     * What an inherit tier in this scope runs on, for the AI Models editor.
     */
    public function inheritedSelection(AiTask $aiTask, string $scope = AiTaskModel::DEFAULT_SCOPE): ResolvedSelection
    {
        throw_if($aiTask === AiTask::Failover, InvalidArgumentException::class, 'Resolve the failover task with failover().');

        $tierPick = $this->inherited($aiTask, $scope);

        return new ResolvedSelection(
            $tierPick->provider,
            $tierPick->model,
            $tierPick->reasoning
                ?? $this->configLevel($aiTask)
                ?? AiReasoningLevel::ProviderDefault,
            $tierPick->tier,
        );
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

    /**
     * The scope's first tier.
     */
    public function row(AiTask $aiTask, string $scope = AiTaskModel::DEFAULT_SCOPE): ?AiTaskModel
    {
        return $this->tiers($aiTask, $scope)->first();
    }

    /**
     * The scope's tiers in position order.
     *
     * @return Collection<int, AiTaskModel>
     */
    public function tiers(AiTask $aiTask, string $scope = AiTaskModel::DEFAULT_SCOPE): Collection
    {
        return $this->rows()
            ->filter(static fn (AiTaskModel $aiTaskModel): bool => $aiTaskModel->task === $aiTask && $aiTaskModel->scope === $scope)
            ->sortBy('position')
            ->values();
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
        $this->picks = [];
    }

    /**
     * The scope's own tier walk, or what it inherits when it has no rows.
     */
    private function pick(AiTask $aiTask, string $scope): TierPick
    {
        return $this->picks[sprintf('%s|%s', $aiTask->value, $scope)] ??= $this->walk($aiTask, $scope) ?? $this->inherited($aiTask, $scope);
    }

    private function walk(AiTask $aiTask, string $scope): ?TierPick
    {
        $tiers = $this->tiers($aiTask, $scope);
        $count = $tiers->count();

        if ($count === 0) {
            return null;
        }

        $skipped = [];

        foreach ($tiers as $index => $tier) {
            if (blank($tier->model)) {
                $parent = $this->inherited($aiTask, $scope);

                return new TierPick(
                    $parent->provider,
                    $parent->model,
                    $tier->reasoning ?? $parent->reasoning,
                    $count > 1 ? new TierOutcome($index + 1, $count, [...$skipped, ...($parent->tier?->reasons ?? [])]) : $parent->tier,
                );
            }

            [$provider, $model] = $this->tierPair($aiTask, $tier);

            if ($index < $count - 1) {
                $reason = $this->tierEligibility->skipReason($provider, $model, $tier->min_pool_percent, $tier->min_pool_tokens);

                if ($reason !== null) {
                    $skipped[] = sprintf('Tier %d: %s', $index + 1, $reason);

                    continue;
                }
            }

            return new TierPick($provider, $model, $tier->reasoning, $count > 1 ? new TierOutcome($index + 1, $count, $skipped) : null);
        }

        throw new LogicException('A tier list always ends in a tier that runs.');
    }

    /**
     * What a scope runs on without tiers of its own (or from its inherit tier).
     */
    private function inherited(AiTask $aiTask, string $scope): TierPick
    {
        if ($scope !== AiTaskModel::DEFAULT_SCOPE) {
            return $this->pick($aiTask, AiTaskModel::DEFAULT_SCOPE);
        }

        return match ($aiTask) {
            AiTask::Chat => new TierPick(...($this->configPair('mediamanager.ai.model') ?? [$this->defaultProvider(), 'gpt-5-mini'])),
            AiTask::Title => new TierPick(...$this->withAutoTitle(...($this->configPair('mediamanager.ai.title_model') ?? [$this->defaultProvider(), 'gpt-5.4-nano']))),
            AiTask::Decision => $this->configPick('mediamanager.decision_agent.model') ?? $this->pick(AiTask::Chat, AiTaskModel::DEFAULT_SCOPE)->pairOnly(),
            AiTask::PriceUpdater => $this->configPick('mediamanager.ai.pricing.updater_model') ?? $this->pick(AiTask::Chat, AiTaskModel::DEFAULT_SCOPE)->pairOnly(),
            AiTask::FileInspector, AiTask::StuckDownloadInvestigator => $this->configPick('mediamanager.ai.sub_agent_model')
                ?? $this->pick(AiTask::Chat, AiTaskModel::DEFAULT_SCOPE)->pairOnly(),
            AiTask::Failover => throw new InvalidArgumentException('Resolve the failover task with failover().'),
        };
    }

    /**
     * A tier's pair: a model saved without a provider (settings from before
     * 1.26.0) runs on `config('ai.default')`; `auto` resolves for titles.
     *
     * @return array{0: string, 1: string}
     */
    private function tierPair(AiTask $aiTask, AiTaskModel $aiTaskModel): array
    {
        $provider = filled($aiTaskModel->provider) ? (string) $aiTaskModel->provider : $this->defaultProvider();

        return $aiTask === AiTask::Title
            ? $this->withAutoTitle($provider, (string) $aiTaskModel->model)
            : [$provider, (string) $aiTaskModel->model];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function withAutoTitle(string $provider, string $model): array
    {
        if ($model !== 'auto') {
            return [$provider, $model];
        }

        try {
            return [$provider, Ai::textProvider($provider)->cheapestTextModel()];
        } catch (InvalidArgumentException|LogicException) {
            return [$provider, $this->configTitleModel()];
        }
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

    private function conversationReasoning(AiTask $aiTask): ?AiReasoningLevel
    {
        return in_array($aiTask, [AiTask::Chat, AiTask::FileInspector, AiTask::StuckDownloadInvestigator], true)
            ? $this->chatTurnContext->reasoning
            : null;
    }

    private function configPick(string $key): ?TierPick
    {
        $pair = $this->configPair($key);

        return $pair === null ? null : new TierPick(...$pair);
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
