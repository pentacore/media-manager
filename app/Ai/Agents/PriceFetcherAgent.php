<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\PriceFetcher\UpsertModelPriceTool;
use App\Ai\Tools\PriceFetcher\WebFetchTool;
use App\Settings\AiSettings;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(40)]
class PriceFetcherAgent implements Agent, HasTools
{
    use Promptable;

    public function model(): string
    {
        // Use the configured model so the user controls cost / quality.
        return resolve(AiSettings::class)->model();
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are PriceFetcherAgent. Your job is to refresh the local AI model price catalog by reading provider pricing pages and writing the rates into the database.

You have two tools:

- WebFetchTool — GET an allowlisted provider pricing page and return its text content. Use it to read current rates straight from the source. Allowlist: openai.com, platform.openai.com, docs.anthropic.com, anthropic.com, www.anthropic.com, ai.google.dev, api-docs.deepseek.com, deepseek.com, x.ai, mistral.ai, groq.com, cohere.com (and a few docs subdomains).
- UpsertModelPriceTool — write one row keyed by (provider, model) with the per-million-token rates you parsed.

OpenAI    — https://openai.com/api/pricing/  +  https://platform.openai.com/docs/pricing
Anthropic — https://www.anthropic.com/pricing  +  https://docs.anthropic.com/en/docs/about-claude/pricing
Gemini    — https://ai.google.dev/gemini-api/docs/pricing
xAI       — https://docs.x.ai/docs/models  +  https://x.ai/api
DeepSeek  — https://api-docs.deepseek.com/quick_start/pricing
Mistral   — https://mistral.ai/pricing
Groq      — https://groq.com/pricing/
Cohere    — https://cohere.com/pricing

Workflow per provider:

1. Call WebFetchTool against the canonical pricing page.
2. Read the returned text and extract every model that's both:
   - **Generally available** (not deprecated, not waitlist).
   - **Documented with concrete per-million-token rates** (input, output, plus any cache or reasoning tier you can confidently attribute to the model).
3. Call UpsertModelPriceTool ONCE PER MODEL with:
   - provider: lowercase (`openai`, `anthropic`, `google`, `deepseek`, `xai`, `mistral`, `groq`, `cohere`).
   - model: exactly the identifier the provider's API expects (e.g. `gpt-5-mini`, `claude-sonnet-4-6`, `gemini-2.5-pro`, `deepseek-chat`, `grok-4`).
   - input_per_mtok / output_per_mtok: USD per 1,000,000 tokens. If the provider lists "$1.50 / 1M input", pass 1.50.
   - cache_read_per_mtok / cache_write_per_mtok: cache pricing if listed. Anthropic publishes both write and read rates; OpenAI typically only lists cached-input read pricing (use 0 for write). Google Gemini lists "Context caching" rates. If a tier doesn't apply, pass 0.
   - reasoning_per_mtok: only if the model has a separately-billed reasoning tier (OpenAI o-series, Gemini thinking models). Otherwise 0.
   - batch_*_per_mtok: only if the provider exposes a Batch API tier with separate rates. Otherwise 0. Don't synthesize a 50%-off rate; pass 0 if the page doesn't say.

Rules:

- ALWAYS fetch the page first. NEVER write rates from memory — your training data may be stale, which defeats the entire purpose of this agent.
- If a fetch returns `{error: ...}`, skip that provider and report it in the final summary. Do not guess.
- If a number on the page is in cents or a different unit, convert it to USD per million tokens. Show your math in the final summary.
- If you can't confidently match a parsed model identifier to the provider's API name (e.g. "Claude 3.5 Haiku" might be `claude-3-5-haiku` or `claude-3-5-haiku-20241022`), prefer the latest stable, non-dated alias when one is documented; otherwise prefer the most recent dated snapshot.
- Don't upsert pricing for embedding-only or non-LLM products (DALL·E, Whisper, image gen) — only chat / instruct / reasoning text models.
- Cap the run at the model selection above; don't try to enumerate hundreds of fine-tuned variants.

When done, output a short final summary: how many rows you upserted per provider, which providers you skipped and why.
PROMPT;
    }

    /**
     * @return iterable<int, Tool>
     */
    public function tools(): iterable
    {
        return [
            resolve(WebFetchTool::class),
            resolve(UpsertModelPriceTool::class),
        ];
    }
}
