<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\AiMode;
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

    private const int TRANSCRIPT_TURNS = 20;

    private const int TRANSCRIPT_CHARS = 1500;

    private const string SCRIPTED_RUBRIC = 'Judge the whole conversation: does each reply follow from the earlier turns, stay consistent with what the assistant said before, and answer the last message?';

    private const int TOOL_RESULTS = 20;

    private const int TOOL_RESULT_CHARS = 2000;

    public static function for(AiTask $aiTask): string
    {
        return match ($aiTask) {
            AiTask::Title => "Concise (at most 6 words), no quotes or trailing punctuation, accurately describes the user's request.",
            AiTask::Chat => "Correct according to the tool results, answers the user's question, invents no facts, follows the mode (in advisory mode it never claims to have performed an action).",
            AiTask::FileInspector, AiTask::StuckDownloadInvestigator => 'Accurate diagnosis according to the tool results, actionable, invents no facts.',
            default => throw new InvalidArgumentException(sprintf('The %s task has no benchmark rubric.', $aiTask->value)),
        };
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  list<array{tool: string, arguments: array<string, mixed>, result: string}>  $toolResults
     * @param  AiMode|null  $mode  the chat mode the candidate ran in, so the judge can grade mode compliance
     * @param  list<array{label: string, name: string, mime_type: string}>  $attachments
     * @param  list<array{role: string, content: string, attachments?: list<string>}>  $transcript  the candidate's scripted conversation; replaces $history when not empty
     */
    public static function judgePrompt(AiTask $task, string $prompt, array $history, array $toolResults, string $output, ?string $rubricNote, ?AiMode $mode = null, array $attachments = [], array $transcript = []): string
    {
        $sections = [
            "TASK\n".$task->label(),
            "RUBRIC\n".self::for($task).($transcript === [] ? '' : "\n".self::SCRIPTED_RUBRIC),
        ];

        if ($mode instanceof AiMode) {
            $sections[] = "MODE\n".$mode->value;
        }

        if (filled($rubricNote)) {
            $sections[] = "CASE NOTE\n".$rubricNote;
        }

        if ($transcript !== []) {
            $turns = array_map(
                static fn (array $turn): string => sprintf('%s: %s', $turn['role'], mb_substr($turn['content'], 0, self::TRANSCRIPT_CHARS)),
                array_slice($transcript, -self::TRANSCRIPT_TURNS),
            );

            $sections[] = 'CONVERSATION (candidate transcript, last '.self::TRANSCRIPT_TURNS." turns)\n".implode("\n", $turns);
        } else {
            $turns = array_map(
                static fn (array $turn): string => sprintf('%s: %s', $turn['role'], mb_substr($turn['content'], 0, self::HISTORY_CHARS)),
                array_slice($history, -self::HISTORY_TURNS),
            );

            $sections[] = 'CONVERSATION (last '.self::HISTORY_TURNS." turns)\n".($turns === [] ? '(none)' : implode("\n", $turns));
        }

        $sections[] = "USER PROMPT\n".$prompt;

        if ($attachments !== []) {
            $sections[] = "ATTACHMENTS\n".implode("\n", array_map(
                static fn (array $attachment): string => sprintf('%s: %s (%s)', $attachment['label'], $attachment['name'], $attachment['mime_type']),
                $attachments,
            ));
        }

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
