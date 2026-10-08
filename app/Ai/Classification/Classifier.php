<?php

declare(strict_types=1);

namespace App\Ai\Classification;

use App\Ai\OpenRouterRequestOptions;
use App\Services\AiUsage\AiUsageCaller;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Throwable;

/**
 * Cheap yes/no/choice calls that gate agent runs. Every caller treats null
 * as "run as today", so a missing key or provider outage never blocks work.
 */
final readonly class Classifier
{
    /** Timeout for gates that run in queued jobs. */
    public const int BACKGROUND_TIMEOUT_SECONDS = 5;

    /** Timeout for gates a person waits on, such as chat tool routing. */
    public const int CHAT_TIMEOUT_SECONDS = 3;

    public function __construct(
        private AiSettings $aiSettings,
        private AiUsageCaller $aiUsageCaller,
        private OpenRouterRequestOptions $openRouterRequestOptions,
    ) {}

    /**
     * @param  string|array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     * @return array<string, Answer>|null
     */
    public function classify(string $caller, string|array $state, array $questions, int $timeoutSeconds = self::BACKGROUND_TIMEOUT_SECONDS): ?array
    {
        $provider = $this->aiSettings->classificationProvider();

        if (! Classification::isFaked() && blank(config(sprintf('ai.providers.%s.key', $provider)))) {
            return null;
        }

        try {
            $response = $this->aiUsageCaller->during($caller, fn (): ClassificationResponse => Classification::of($state)
                ->questions($questions)
                ->timeout($timeoutSeconds)
                ->withProviderOptions(fn (Provider $resolvedProvider): array => $this->openRouterRequestOptions->for($resolvedProvider->driver()))
                ->classify($provider, $this->aiSettings->classificationModel()));

            return array_combine(
                array_keys($questions),
                array_map($response->answer(...), array_keys($questions)),
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
    public function probability(string $caller, string|array $state, string $question, int $timeoutSeconds = self::BACKGROUND_TIMEOUT_SECONDS): ?float
    {
        $answer = $this->classify($caller, $state, ['decision' => new Boolean($question)], $timeoutSeconds)['decision'] ?? null;

        return $answer instanceof BooleanAnswer ? $answer->probability : null;
    }
}
