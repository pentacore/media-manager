<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\AiMode;

class AiSettings
{
    public const MODE_KEY = 'ai.mode';

    public const MODEL_KEY = 'ai.model';

    /**
     * Per-request override that takes precedence over the persisted mode.
     * Used by the chat surface so a user can flip between Advisory and
     * Executive for a single turn without mutating global state.
     */
    private ?AiMode $modeOverride = null;

    public function __construct(private readonly AppSettings $appSettings) {}

    public function mode(): AiMode
    {
        if ($this->modeOverride instanceof AiMode) {
            return $this->modeOverride;
        }

        $value = (string) $this->appSettings->get(
            self::MODE_KEY,
            config('mediamanager.ai.mode', AiMode::Executive->value),
        );

        return AiMode::tryFrom($value) ?? AiMode::Executive;
    }

    public function withMode(AiMode $aiMode): void
    {
        $this->modeOverride = $aiMode;
    }

    public function model(): string
    {
        $value = (string) $this->appSettings->get(
            self::MODEL_KEY,
            config('mediamanager.ai.model', 'gpt-5-mini'),
        );

        return $value !== '' ? $value : 'gpt-5-mini';
    }

    public function setMode(AiMode $aiMode): void
    {
        $this->appSettings->set(self::MODE_KEY, $aiMode->value);
    }

    public function setModel(string $model): void
    {
        $this->appSettings->set(self::MODEL_KEY, $model);
    }
}
