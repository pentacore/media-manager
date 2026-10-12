<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\AiTask;
use InvalidArgumentException;

/**
 * The per-task grading rubric and the user prompt the benchmark judge gets.
 * The prompt is blind: it never carries the candidate's provider or model.
 */
final class DefaultRubrics
{
    private const int HISTORY_TURNS = 6;

    private const int HISTORY_CHARS = 500;

    private const int TOOL_RESULTS = 20;

    private const int TOOL_RESULT_CHARS = 2000;

    public static function for(AiTask $aiTask): string
    {
        return match ($aiTask) {
            AiTask::Title => "Concise (at most 6 words), no quotes or trailing punctuation, accurately describes the user's request.",
            AiTask::Chat => 'Correct according to the tool results, answers the user\'s question, invents no facts, follows the mode (in advisory mode it never claims to have performed an action).',
            AiTask::FileInspector, AiTask::StuckDownloadInvestigator => 'Accurate diagnosis according to the tool results, actionable, invents no facts.',
            default => throw new InvalidArgumentException(sprintf('The %s task has no benchmark rubric.', $aiTask->value)),
        };
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  list<array{tool: string, arguments: array<string, mixed>, result: string}>  $toolResults
     */
    public static function judgePrompt(AiTask $task, string $prompt, array $history, array $toolResults, string $output, ?string $rubricNote): string
    {
        $sections = [
            "TASK\n".$task->label(),
            "RUBRIC\n".self::for($task),
        ];

        if (filled($rubricNote)) {
            $sections[] = "CASE NOTE\n".$rubricNote;
        }

        $turns = array_map(
            static fn (array $turn): string => sprintf('%s: %s', $turn['role'], mb_substr($turn['content'], 0, self::HISTORY_CHARS)),
            array_slice($history, -self::HISTORY_TURNS),
        );

        $sections[] = 'CONVERSATION (last '.self::HISTORY_TURNS." turns)\n".($turns === [] ? '(none)' : implode("\n", $turns));
        $sections[] = "USER PROMPT\n".$prompt;

        $results = array_map(
            static fn (array $call): string => sprintf(
                '%s(%s) → %s',
                $call['tool'],
                json_encode($call['arguments'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                mb_substr($call['result'], 0, self::TOOL_RESULT_CHARS),
            ),
            array_slice($toolResults, 0, self::TOOL_RESULTS),
        );

        $sections[] = "TOOL RESULTS\n".($results === [] ? '(none)' : implode("\n", $results));
        $sections[] = "CANDIDATE OUTPUT\n".$output;

        return implode("\n\n", $sections);
    }
}
