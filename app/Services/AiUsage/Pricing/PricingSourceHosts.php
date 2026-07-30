<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\Tools\PriceFetcher\UpsertModelPriceTool;
use App\Ai\Tools\PriceFetcher\WebFetchTool;

/**
 * The single source of truth binding every allowlisted pricing-page host to the
 * canonical (Laravel AI identity) provider it belongs to.
 *
 * This map is derived from the union of {@see PriceFetcherAgent}'s
 * canonical source pages and the pricing-page host allowlist. Keeping one map
 * here — rather than a bare host allowlist in one place and a provider lookup in
 * another — is what lets both pricing tools agree on provenance without
 * duplication:
 *
 * - {@see WebFetchTool} restricts fetches to
 *   {@see hosts()} and records the canonical provider of every fetch receipt.
 * - {@see UpsertModelPriceTool} refuses to stamp a
 *   first-party price unless the `source_url` host maps to the SAME canonical
 *   provider as the candidate, closing the cross-provider receipt-forgery gap
 *   where an Anthropic page could authorize an OpenAI write.
 *
 * Docs / console / platform subdomains map to the same provider as their
 * primary domain (for example `platform.claude.com` and `docs.anthropic.com`
 * both resolve to `anthropic`, and `cloud.google.com` resolves to `gemini`).
 *
 * This class holds no per-request state and is safe to call statically under
 * Octane and long-running workers.
 */
final class PricingSourceHosts
{
    /**
     * Canonical pricing-page host => canonical provider identity.
     *
     * @var array<string, string>
     */
    public const array HOST_PROVIDERS = [
        'openai.com' => 'openai',
        'platform.openai.com' => 'openai',
        'developers.openai.com' => 'openai',
        'anthropic.com' => 'anthropic',
        'www.anthropic.com' => 'anthropic',
        'docs.anthropic.com' => 'anthropic',
        'claude.com' => 'anthropic',
        'www.claude.com' => 'anthropic',
        'platform.claude.com' => 'anthropic',
        'docs.claude.com' => 'anthropic',
        'ai.google.dev' => 'gemini',
        'cloud.google.com' => 'gemini',
        'deepseek.com' => 'deepseek',
        'api-docs.deepseek.com' => 'deepseek',
        'x.ai' => 'xai',
        'docs.x.ai' => 'xai',
        'mistral.ai' => 'mistral',
        'docs.mistral.ai' => 'mistral',
        'groq.com' => 'groq',
        'console.groq.com' => 'groq',
        'cohere.com' => 'cohere',
        'docs.cohere.com' => 'cohere',
        'openrouter.ai' => 'openrouter',
    ];

    /**
     * The pricing-page host allowlist (every mapped host), in declaration order.
     *
     * @return list<string>
     */
    public static function hosts(): array
    {
        return array_keys(self::HOST_PROVIDERS);
    }

    /**
     * The canonical provider bound to the given host, or null when the host is
     * not on the pricing allowlist.
     */
    public static function providerForHost(string $host): ?string
    {
        return self::HOST_PROVIDERS[strtolower(trim($host))] ?? null;
    }

    /**
     * The canonical provider derived from a URL's host, or null when the URL is
     * empty, malformed, hostless, or its host is not on the pricing allowlist.
     */
    public static function providerForUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        return self::providerForHost($parts['host']);
    }
}
