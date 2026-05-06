<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class TitleAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
You are a title summarizer. Given a single user message that opens a chat conversation,
write a concise 4-6 word chat title summarizing the topic. Output ONLY the title — no
quotes, no trailing punctuation, no preamble. Title case is fine.
PROMPT;
    }
}
