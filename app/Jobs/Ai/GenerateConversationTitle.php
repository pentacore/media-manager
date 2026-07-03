<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Ai\Agents\TitleAgent;
use App\Settings\AiSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class GenerateConversationTitle implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $conversationId,
        public string $firstUserMessage,
    ) {}

    public function handle(AiSettings $aiSettings): void
    {
        $titleAgent = new TitleAgent;
        $chain = $aiSettings->providerChainWithModel($aiSettings->titleModel());

        try {
            $response = $chain === null
                ? $titleAgent->prompt($this->firstUserMessage, model: $aiSettings->titleModel())
                : $titleAgent->prompt($this->firstUserMessage, provider: $chain);
        } catch (Throwable $throwable) {
            // Soft failure — the controller already wrote a fallback title
            // (truncated first message) before dispatching this job, so the
            // user still sees something readable in the picker.
            report($throwable);

            return;
        }

        $title = $this->normalize((string) ($response['title'] ?? ''));

        if ($title === '') {
            return;
        }

        DB::table('agent_conversations')
            ->where('id', $this->conversationId)
            ->update([
                'title' => $title,
                'updated_at' => now(),
            ]);
    }

    private function normalize(string $raw): string
    {
        $cleaned = trim($raw, " \t\n\r\0\x0B\"'.\u{2018}\u{2019}\u{201C}\u{201D}");
        $cleaned = (string) preg_replace('/\s+/', ' ', $cleaned);

        return (string) Str::limit($cleaned, 80, '');
    }
}
