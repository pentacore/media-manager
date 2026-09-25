<?php

declare(strict_types=1);

namespace App\Ai\Classification;

use App\Services\AiUsage\AiUsageCaller;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Throwable;

/**
 * Cheap yes/no/choice calls that gate agent runs. Every caller treats null
 * as "run as today", so a missing key or provider outage never blocks work.
 */
final readonly class Classifier
{
    private const int TIMEOUT_SECONDS = 10;

    public function __construct(
        private AiSettings $aiSettings,
        private AiUsageCaller $aiUsageCaller,
    ) {}

    /**
     * @param  string|array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     * @return array<string, Answer>|null
     */
    public function classify(string $caller, string|array $state, array $questions): ?array
    {
        $provider = $this->aiSettings->classificationProvider();

        if (! Classification::isFaked() && blank(config(sprintf('ai.providers.%s.key', $provider)))) {
            return null;
        }

        try {
            $response = $this->aiUsageCaller->during($caller, fn () => Classification::of($state)
                ->questions($questions)
                ->timeout(self::TIMEOUT_SECONDS)
                ->classify($provider, $this->aiSettings->classificationModel()));

            return array_combine(
                array_keys($questions),
                array_map(static fn (string $key): Answer => $response->answer($key), array_keys($questions)),
            );
        } catch (Throwable $throwable) {
            Log::warning('Classification failed; failing open.', [
                'caller' => $caller,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  string|array<string, mixed>  $state
     */
    public function probability(string $caller, string|array $state, string $question): ?float
    {
        $answer = $this->classify($caller, $state, ['decision' => new Boolean($question)])['decision'] ?? null;

        return $answer instanceof BooleanAnswer ? $answer->probability : null;
    }
}
