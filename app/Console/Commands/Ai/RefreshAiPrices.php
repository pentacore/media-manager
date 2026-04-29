<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Ai\Agents\PriceFetcherAgent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:refresh-prices')]
#[Description('Runs the PriceFetcherAgent: visits provider pricing pages and upserts current rates into ai_model_prices.')]
class RefreshAiPrices extends Command
{
    public function handle(): int
    {
        $this->info('Running PriceFetcherAgent — this fetches live pricing from provider pages.');

        $response = (new PriceFetcherAgent)->prompt(
            'Refresh the catalog now. Visit the canonical pricing page for OpenAI, Anthropic, Google Gemini, DeepSeek, xAI, and Mistral. Upsert one row per generally-available text/chat model with up-to-date input, output, cache, and reasoning rates. Skip image / audio / embedding products.'
        );

        $this->line('');
        $this->line($response->text);
        $this->line('');
        $this->info('Done.');

        return self::SUCCESS;
    }
}
