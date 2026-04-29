<?php

declare(strict_types=1);

namespace App\Ai\Tools\PriceFetcher;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Minimal HTTP GET tool used by the PriceFetcherAgent to scrape provider
 * pricing pages. Returns the response body trimmed of common HTML chrome
 * so the LLM doesn't choke on the full DOM. Read-only, host-restricted to
 * a small allowlist of pricing pages so the agent can't be talked into
 * fetching arbitrary URLs.
 */
class WebFetchTool extends BaseTool
{
    /**
     * Hosts the agent is allowed to fetch from. Pricing pages only.
     */
    private const array ALLOWED_HOSTS = [
        'openai.com',
        'platform.openai.com',
        'docs.anthropic.com',
        'anthropic.com',
        'www.anthropic.com',
        'ai.google.dev',
        'cloud.google.com',
        'api-docs.deepseek.com',
        'deepseek.com',
        'x.ai',
        'docs.x.ai',
        'mistral.ai',
        'docs.mistral.ai',
        'groq.com',
        'console.groq.com',
        'cohere.com',
        'docs.cohere.com',
    ];

    public function description(): Stringable|string
    {
        return 'Fetch a single provider pricing page and return its text content (HTML stripped) for parsing. Allowed hosts: '.implode(', ', self::ALLOWED_HOSTS).'. Use this to read current model prices off the provider documentation.';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $args = $request->toArray();
        $url = (string) ($args['url'] ?? '');

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || ! in_array(strtolower($host), self::ALLOWED_HOSTS, true)) {
            return [
                'error' => 'host_not_allowed',
                'message' => 'Host not in pricing-pages allowlist. Pick one of: '.implode(', ', self::ALLOWED_HOSTS),
            ];
        }

        $response = Http::timeout(15)
            ->connectTimeout(5)
            ->withUserAgent('Mozilla/5.0 MediaManager-PriceFetcher/1.0')
            ->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
            ->get($url);

        if (! $response->successful()) {
            return [
                'error' => 'fetch_failed',
                'status' => $response->status(),
                'message' => 'Upstream returned non-2xx.',
            ];
        }

        return [
            'url' => $url,
            'status' => $response->status(),
            'content' => $this->reduce($response->body()),
        ];
    }

    /**
     * Strip scripts, styles, and HTML tags. Collapse whitespace. Cap to
     * 60k characters so a giant docs page doesn't blow up the prompt.
     */
    private function reduce(string $html): string
    {
        $stripped = (string) preg_replace('#<(script|style)[^>]*>.*?</\1>#si', '', $html);
        $stripped = strip_tags($stripped);
        $stripped = (string) preg_replace('/\s+/', ' ', $stripped);
        $stripped = trim(html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5));

        return mb_substr($stripped, 0, 60_000);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->description('Absolute URL of a provider pricing page. Must be on the allowlist (openai.com, anthropic.com, ai.google.dev, deepseek.com, x.ai, mistral.ai, groq.com, cohere.com or their docs subdomains).')
                ->required(),
        ];
    }
}
