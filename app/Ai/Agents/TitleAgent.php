<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Settings\AiSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class TitleAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function model(): string
    {
        return resolve(AiSettings::class)->titleModel();
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
You are a title summarizer. Given a single user message that opens a chat conversation,
write a concise 4-6 word chat title summarizing the topic. Output ONLY the title — no
quotes, no trailing punctuation, no preamble. Title case is fine.
PROMPT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('A 4-6 word conversation title. No quotes, no trailing punctuation.')
                ->required(),
        ];
    }
}
