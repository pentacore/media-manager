<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\AiMode;

class AiSettings
{
    public const MODE_KEY = 'ai.mode';

    public const MODEL_KEY = 'ai.model';

    public function __construct(private readonly AppSettings $appSettings) {}

    public function mode(): AiMode
    {
        $value = (string) $this->appSettings->get(
            self::MODE_KEY,
            config('mediamanager.ai.mode', AiMode::Executive->value),
        );

        return AiMode::tryFrom($value) ?? AiMode::Executive;
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
