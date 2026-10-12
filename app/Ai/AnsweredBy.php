<?php

declare(strict_types=1);

namespace App\Ai;

use App\Enums\AiTask;

/**
 * Names who answered a chat turn: the model the provider reports, with the
 * reasoning level and tier the Chat task resolved to for this request.
 */
final class AnsweredBy
{
    /**
     * @return array{provider: string, model: string, reasoning_label: string, tier: array{position: int, count: int, reason: string|null, via: string|null}|null}
     */
    public static function forChat(?string $provider, ?string $model): array
    {
        $resolvedSelection = resolve(TaskModelResolver::class)->resolve(AiTask::Chat);

        return [
            'provider' => $provider ?? $resolvedSelection->provider,
            'model' => $model ?? $resolvedSelection->model,
            'reasoning_label' => $resolvedSelection->reasoning->label(),
            'tier' => $resolvedSelection->tier?->toArray(),
        ];
    }
}
