<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Files\File;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\ClassificationResponse;

/**
 * Builds the prompt_text / response_text columns of ai_usage_records. Both
 * are detail-modal use only (never indexed or searched), capped at 64 KB so
 * a runaway prompt or reply can't bloat the row.
 */
final class UsageText
{
    private const int MAX_BYTES = 65_536;

    private const int JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * The prompt text an agent run was sent, with attachment names appended.
     */
    public static function agentInput(AgentPrompt $agentPrompt): ?string
    {
        $attachmentNames = $agentPrompt->attachments
            ->map(static fn (mixed $attachment): string => match (true) {
                $attachment instanceof File => $attachment->name() ?? class_basename($attachment),
                $attachment instanceof UploadedFile => $attachment->getClientOriginalName(),
                default => get_debug_type($attachment),
            })
            ->all();

        if ($attachmentNames === []) {
            return self::truncate($agentPrompt->prompt);
        }

        return self::truncate(sprintf("%s\n\nAttachments: %s", $agentPrompt->prompt, implode(', ', $attachmentNames)));
    }

    /**
     * The classified state and the questions asked about it, as pretty JSON.
     */
    public static function classificationInput(ClassificationPrompt $classificationPrompt): ?string
    {
        return self::truncate((string) json_encode([
            'state' => $classificationPrompt->state,
            'questions' => array_map(static fn (Question $question): array => $question->toArray(), $classificationPrompt->questions),
        ], self::JSON_FLAGS));
    }

    /**
     * The answers a classification call returned, keyed by question, as pretty JSON.
     */
    public static function classificationOutput(ClassificationResponse $classificationResponse): ?string
    {
        return self::truncate((string) json_encode($classificationResponse->answers, self::JSON_FLAGS));
    }

    public static function truncate(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        if (mb_strlen($text, '8bit') <= self::MAX_BYTES) {
            return $text;
        }

        return mb_strcut($text, 0, self::MAX_BYTES - 3, 'UTF-8').'…';
    }
}
