<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\AiMode;

class AiSettings
{
    public const MODE_KEY = 'ai.mode';

    public const COMMAND_MODEL_KEY = 'ai.command_model';

    public const ADVISOR_MODEL_KEY = 'ai.advisor_model';

    public function __construct(private readonly AppSettings $appSettings) {}

    public function mode(): AiMode
    {
        $value = (string) $this->appSettings->get(
            self::MODE_KEY,
            config('mediamanager.ai.mode', AiMode::Executive->value),
        );

        return AiMode::tryFrom($value) ?? AiMode::Executive;
    }

    public function commandModel(): string
    {
        return (string) $this->appSettings->get(
            self::COMMAND_MODEL_KEY,
            config('mediamanager.ai.command_model', 'gpt-5-mini'),
        );
    }

    public function advisorModel(): string
    {
        return (string) $this->appSettings->get(
            self::ADVISOR_MODEL_KEY,
            config('mediamanager.ai.advisor_model', 'gpt-5-mini'),
        );
    }

    public function setMode(AiMode $aiMode): void
    {
        $this->appSettings->set(self::MODE_KEY, $aiMode->value);
    }

    public function setCommandModel(string $model): void
    {
        $this->appSettings->set(self::COMMAND_MODEL_KEY, $model);
    }

    public function setAdvisorModel(string $model): void
    {
        $this->appSettings->set(self::ADVISOR_MODEL_KEY, $model);
    }
}
