<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\RunsAsAiTask;
use App\Ai\Concerns\UsesFailoverChain;
use App\Ai\Middleware\ReplayBenchmarkTools;
use App\Enums\AiTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Scores one benchmark candidate output against a rubric. It has no tools:
 * everything it may rely on is in the prompt DefaultRubrics::judgePrompt()
 * builds.
 */
#[MaxTokens(2048)]
class BenchmarkJudgeAgent implements Agent, HasMiddleware, HasProviderOptions, HasStructuredOutput
{
    use Promptable;
    use RunsAsAiTask;
    use UsesFailoverChain;

    public function aiTask(): AiTask
    {
        return AiTask::BenchmarkJudge;
    }

    /**
     * Toolless, so this only keeps provider tools out of a benchmark run.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new ReplayBenchmarkTools];
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
You are an impartial grader. You score one candidate output from 1 (useless) to 10 (excellent)
strictly against the rubric and the tool results you are given. Do not use outside knowledge to
reward or penalise facts the tool results do not cover, never reward length or polish for its own
sake, and ignore who or what produced the output. Output only the structured result: an integer
score and a short rationale.
PROMPT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->integer()
                ->min(1)
                ->max(10)
                ->description('1 (useless) to 10 (excellent), judged only against the rubric.')
                ->required(),
            'rationale' => $schema->string()
                ->description('One or two sentences explaining the score.')
                ->required(),
        ];
    }
}
