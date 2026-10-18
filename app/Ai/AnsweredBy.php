<?php

declare(strict_types=1);

namespace App\Ai;

use App\Enums\AiTask;

/**
 * Names who answered a chat turn: the model the provider reports, with the
 * reasoning level and tier the Chat task resolved to for this request.
 *
 * A failover answer has no tier, and its reasoning is the level the failover
 * request sent: the turn's level clamped for the configured failover model
 * (TaskModelResolver::failoverModel(), the model providerOptions() clamped
 * against), never the reported one. It is told apart by provider alone: the
 * failover chain never repeats the primary provider, and some providers
 * report a dated model snapshot (or nothing) rather than the model that was
 * asked for.
 */
final class AnsweredBy
{
    /**
     * @return array{provider: string, model: string, reasoning_label: string, tier: array{position: int, count: int, reason: string|null, via: string|null}|null}
     */
    public static function forChat(?string $provider, ?string $model): array
    {
        $taskModelResolver = resolve(TaskModelResolver::class);
        $resolvedSelection = $taskModelResolver->resolve(AiTask::Chat);

        if ($provider !== null && $provider !== $resolvedSelection->provider) {
            $configuredModel = ($taskModelResolver->failover()['provider'] ?? null) === $provider
                ? $taskModelResolver->failoverModel()
                : null;

            return [
                'provider' => $provider,
                'model' => filled($model) ? $model : (string) $configuredModel,
                'reasoning_label' => resolve(ReasoningOptions::class)
                    ->effectiveLevel($provider, $configuredModel ?? $model, $resolvedSelection->reasoning)
                    ->label(),
                'tier' => null,
            ];
        }

        return [
            'provider' => $provider ?? $resolvedSelection->provider,
            'model' => $model ?? $resolvedSelection->model,
            'reasoning_label' => $resolvedSelection->reasoning->label(),
            'tier' => $resolvedSelection->tier?->toArray(),
        ];
    }
}
