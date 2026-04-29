<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AiModelPrice;
use Illuminate\Database\Seeder;

class AiModelPriceSeeder extends Seeder
{
    public function run(): void
    {
        // Pricing in USD per million tokens (MTok). Verified against provider pricing pages April 2026.
        // reasoning_per_mtok mirrors output rate when the model bills thinking tokens separately from output;
        // 0 when reasoning is either non-applicable or folded into the output rate (Anthropic extended thinking).
        //
        // Pricing source pages — check these when refreshing the seeder:
        //   OpenAI    — https://openai.com/api/pricing/  +  https://platform.openai.com/docs/pricing
        //   Anthropic — https://www.anthropic.com/pricing  +  https://docs.anthropic.com/en/docs/about-claude/pricing
        //   Gemini    — https://ai.google.dev/gemini-api/docs/pricing
        //   xAI       — https://docs.x.ai/docs/models  +  https://x.ai/api
        //   DeepSeek  — https://api-docs.deepseek.com/quick_start/pricing
        //   Mistral   — https://mistral.ai/pricing
        //   Groq      — https://groq.com/pricing/
        //   Cohere    — https://cohere.com/pricing
        $defaults = [
            // OpenAI — GPT-5.x family.
            ['provider' => 'openai', 'model' => 'gpt-5.5', 'input_per_mtok' => 5.00, 'output_per_mtok' => 30.00, 'cache_read_per_mtok' => 0.50, 'cache_write_per_mtok' => 5.00, 'reasoning_per_mtok' => 30.00],
            ['provider' => 'openai', 'model' => 'gpt-5.5-pro', 'input_per_mtok' => 30.00, 'output_per_mtok' => 180.00, 'cache_read_per_mtok' => 3.00, 'cache_write_per_mtok' => 30.00, 'reasoning_per_mtok' => 180.00],
            ['provider' => 'openai', 'model' => 'gpt-5.4', 'input_per_mtok' => 2.50, 'output_per_mtok' => 15.00, 'cache_read_per_mtok' => 0.25, 'cache_write_per_mtok' => 2.50, 'reasoning_per_mtok' => 15.00],
            ['provider' => 'openai', 'model' => 'gpt-5.4-mini', 'input_per_mtok' => 0.75, 'output_per_mtok' => 4.50, 'cache_read_per_mtok' => 0.075, 'cache_write_per_mtok' => 0.75, 'reasoning_per_mtok' => 4.50],
            ['provider' => 'openai', 'model' => 'gpt-5.4-nano', 'input_per_mtok' => 0.20, 'output_per_mtok' => 1.25, 'cache_read_per_mtok' => 0.02, 'cache_write_per_mtok' => 0.20, 'reasoning_per_mtok' => 1.25],
            ['provider' => 'openai', 'model' => 'gpt-5.3-codex', 'input_per_mtok' => 1.75, 'output_per_mtok' => 14.00, 'cache_read_per_mtok' => 0.175, 'cache_write_per_mtok' => 1.75, 'reasoning_per_mtok' => 14.00],
            ['provider' => 'openai', 'model' => 'gpt-5.1', 'input_per_mtok' => 1.25, 'output_per_mtok' => 10.00, 'cache_read_per_mtok' => 0.125, 'cache_write_per_mtok' => 1.25, 'reasoning_per_mtok' => 10.00],
            ['provider' => 'openai', 'model' => 'gpt-realtime-1.5', 'input_per_mtok' => 4.00, 'output_per_mtok' => 16.00, 'cache_read_per_mtok' => 0.40, 'cache_write_per_mtok' => 4.00, 'reasoning_per_mtok' => 0],

            // Anthropic — Claude 4.x. cache_write shown is 5-minute (1.25x input); 1-hour cache write is 2x input.
            ['provider' => 'anthropic', 'model' => 'claude-opus-4-7', 'input_per_mtok' => 5.00, 'output_per_mtok' => 25.00, 'cache_read_per_mtok' => 0.50, 'cache_write_per_mtok' => 6.25, 'reasoning_per_mtok' => 0],
            ['provider' => 'anthropic', 'model' => 'claude-opus-4-6', 'input_per_mtok' => 5.00, 'output_per_mtok' => 25.00, 'cache_read_per_mtok' => 0.50, 'cache_write_per_mtok' => 6.25, 'reasoning_per_mtok' => 0],
            ['provider' => 'anthropic', 'model' => 'claude-opus-4-5', 'input_per_mtok' => 5.00, 'output_per_mtok' => 25.00, 'cache_read_per_mtok' => 0.50, 'cache_write_per_mtok' => 6.25, 'reasoning_per_mtok' => 0],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'input_per_mtok' => 3.00, 'output_per_mtok' => 15.00, 'cache_read_per_mtok' => 0.30, 'cache_write_per_mtok' => 3.75, 'reasoning_per_mtok' => 0],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'input_per_mtok' => 3.00, 'output_per_mtok' => 15.00, 'cache_read_per_mtok' => 0.30, 'cache_write_per_mtok' => 3.75, 'reasoning_per_mtok' => 0],
            ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'input_per_mtok' => 1.00, 'output_per_mtok' => 5.00, 'cache_read_per_mtok' => 0.10, 'cache_write_per_mtok' => 1.25, 'reasoning_per_mtok' => 0],

            // Google Gemini — 3.x preview + 2.5 GA. Prices are <=200K-token tier.
            ['provider' => 'gemini', 'model' => 'gemini-3.1-pro-preview', 'input_per_mtok' => 2.00, 'output_per_mtok' => 12.00, 'cache_read_per_mtok' => 0.20, 'cache_write_per_mtok' => 2.00, 'reasoning_per_mtok' => 12.00],
            ['provider' => 'gemini', 'model' => 'gemini-3.1-flash-lite-preview', 'input_per_mtok' => 0.25, 'output_per_mtok' => 1.50, 'cache_read_per_mtok' => 0.025, 'cache_write_per_mtok' => 0.25, 'reasoning_per_mtok' => 1.50],
            ['provider' => 'gemini', 'model' => 'gemini-3-flash-preview', 'input_per_mtok' => 0.50, 'output_per_mtok' => 3.00, 'cache_read_per_mtok' => 0.05, 'cache_write_per_mtok' => 0.50, 'reasoning_per_mtok' => 3.00],
            ['provider' => 'gemini', 'model' => 'gemini-2.5-pro', 'input_per_mtok' => 1.25, 'output_per_mtok' => 10.00, 'cache_read_per_mtok' => 0.125, 'cache_write_per_mtok' => 1.25, 'reasoning_per_mtok' => 10.00],
            ['provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'input_per_mtok' => 0.30, 'output_per_mtok' => 2.50, 'cache_read_per_mtok' => 0.03, 'cache_write_per_mtok' => 0.30, 'reasoning_per_mtok' => 2.50],
            ['provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'input_per_mtok' => 0.10, 'output_per_mtok' => 0.40, 'cache_read_per_mtok' => 0.01, 'cache_write_per_mtok' => 0.10, 'reasoning_per_mtok' => 0],

            // xAI Grok.
            ['provider' => 'xai', 'model' => 'grok-4.1-fast', 'input_per_mtok' => 0.20, 'output_per_mtok' => 0.50, 'cache_read_per_mtok' => 0.05, 'cache_write_per_mtok' => 0.20, 'reasoning_per_mtok' => 0],
            ['provider' => 'xai', 'model' => 'grok-4.1-fast-thinking', 'input_per_mtok' => 0.20, 'output_per_mtok' => 0.50, 'cache_read_per_mtok' => 0.05, 'cache_write_per_mtok' => 0.20, 'reasoning_per_mtok' => 0.50],
            ['provider' => 'xai', 'model' => 'grok-4-fast', 'input_per_mtok' => 0.20, 'output_per_mtok' => 0.50, 'cache_read_per_mtok' => 0.05, 'cache_write_per_mtok' => 0.20, 'reasoning_per_mtok' => 0],
            ['provider' => 'xai', 'model' => 'grok-code-fast-1', 'input_per_mtok' => 0.20, 'output_per_mtok' => 1.50, 'cache_read_per_mtok' => 0.02, 'cache_write_per_mtok' => 0.20, 'reasoning_per_mtok' => 0],
            ['provider' => 'xai', 'model' => 'grok-4', 'input_per_mtok' => 3.00, 'output_per_mtok' => 15.00, 'cache_read_per_mtok' => 0.75, 'cache_write_per_mtok' => 3.00, 'reasoning_per_mtok' => 15.00],
            ['provider' => 'xai', 'model' => 'grok-3-mini', 'input_per_mtok' => 0.25, 'output_per_mtok' => 0.50, 'cache_read_per_mtok' => 0.075, 'cache_write_per_mtok' => 0.25, 'reasoning_per_mtok' => 0],

            // DeepSeek.
            ['provider' => 'deepseek', 'model' => 'deepseek-v4-flash', 'input_per_mtok' => 0.14, 'output_per_mtok' => 0.28, 'cache_read_per_mtok' => 0.0028, 'cache_write_per_mtok' => 0.14, 'reasoning_per_mtok' => 0.28],
            ['provider' => 'deepseek', 'model' => 'deepseek-v4-pro', 'input_per_mtok' => 0.435, 'output_per_mtok' => 0.87, 'cache_read_per_mtok' => 0.0036, 'cache_write_per_mtok' => 0.435, 'reasoning_per_mtok' => 0.87],

            // Mistral.
            ['provider' => 'mistral', 'model' => 'mistral-large-2512', 'input_per_mtok' => 0.50, 'output_per_mtok' => 1.50, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'mistral', 'model' => 'mistral-large-2411', 'input_per_mtok' => 2.00, 'output_per_mtok' => 6.00, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'mistral', 'model' => 'mistral-medium-3.1', 'input_per_mtok' => 0.40, 'output_per_mtok' => 2.00, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'mistral', 'model' => 'mistral-small-3.2', 'input_per_mtok' => 0.075, 'output_per_mtok' => 0.20, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'mistral', 'model' => 'magistral-medium', 'input_per_mtok' => 2.00, 'output_per_mtok' => 5.00, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 5.00],
            ['provider' => 'mistral', 'model' => 'codestral-2508', 'input_per_mtok' => 0.30, 'output_per_mtok' => 0.90, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'mistral', 'model' => 'devstral-2-2512', 'input_per_mtok' => 0.40, 'output_per_mtok' => 0.90, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],

            // Groq — hosted open-weights.
            ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b', 'input_per_mtok' => 0.15, 'output_per_mtok' => 0.60, 'cache_read_per_mtok' => 0.075, 'cache_write_per_mtok' => 0.15, 'reasoning_per_mtok' => 0],
            ['provider' => 'groq', 'model' => 'openai/gpt-oss-20b', 'input_per_mtok' => 0.075, 'output_per_mtok' => 0.30, 'cache_read_per_mtok' => 0.0375, 'cache_write_per_mtok' => 0.075, 'reasoning_per_mtok' => 0],
            ['provider' => 'groq', 'model' => 'meta-llama/llama-4-scout-17b-16e-instruct', 'input_per_mtok' => 0.11, 'output_per_mtok' => 0.34, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'groq', 'model' => 'qwen/qwen3-32b', 'input_per_mtok' => 0.29, 'output_per_mtok' => 0.59, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'groq', 'model' => 'moonshotai/kimi-k2-instruct-0905', 'input_per_mtok' => 1.00, 'output_per_mtok' => 3.00, 'cache_read_per_mtok' => 0.50, 'cache_write_per_mtok' => 1.00, 'reasoning_per_mtok' => 0],
            ['provider' => 'groq', 'model' => 'llama-3.3-70b-versatile', 'input_per_mtok' => 0.59, 'output_per_mtok' => 0.79, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'groq', 'model' => 'llama-3.1-8b-instant', 'input_per_mtok' => 0.05, 'output_per_mtok' => 0.08, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],

            // Cohere — Command family.
            ['provider' => 'cohere', 'model' => 'command-a-03-2025', 'input_per_mtok' => 2.50, 'output_per_mtok' => 10.00, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'cohere', 'model' => 'command-r-plus-08-2024', 'input_per_mtok' => 2.50, 'output_per_mtok' => 10.00, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'cohere', 'model' => 'command-r-08-2024', 'input_per_mtok' => 0.15, 'output_per_mtok' => 0.60, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
            ['provider' => 'cohere', 'model' => 'command-r7b-12-2024', 'input_per_mtok' => 0.0375, 'output_per_mtok' => 0.15, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0],
        ];

        foreach ($defaults as $default) {
            AiModelPrice::updateOrCreate(
                ['provider' => $default['provider'], 'model' => $default['model']],
                $default,
            );
        }
    }
}
