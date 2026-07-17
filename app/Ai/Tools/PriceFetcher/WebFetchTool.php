<?php

declare(strict_types=1);

namespace App\Ai\Tools\PriceFetcher;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
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
     * Redirect hops to follow before giving up. Each hop is re-validated
     * against the host allowlist.
     */
    public const int MAX_REDIRECTS = 5;

    /**
     * Hosts the agent is allowed to fetch from. Pricing pages only.
     */
    public const array ALLOWED_HOSTS = [
        'openai.com',
        'platform.openai.com',
        'developers.openai.com',
        'docs.anthropic.com',
        'anthropic.com',
        'www.anthropic.com',
        'claude.com',
        'www.claude.com',
        'platform.claude.com',
        'docs.claude.com',
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
        'openrouter.ai',
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

        // Redirects are followed manually so that *every* hop — not just the
        // first and last URL — is validated against the allowlist. Automatic
        // following would let an allowlisted page bounce the request to an
        // arbitrary (e.g. internal) host before the final-host check ran.
        $response = null;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $rejection = $this->rejectUrl($url, $hop);
            if ($rejection !== null) {
                return $rejection;
            }

            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withUserAgent('Mozilla/5.0 MediaManager-PriceFetcher/1.0')
                ->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
                ->withoutRedirecting()
                ->get($url);

            if (! $response->redirect()) {
                break;
            }

            $location = $response->header('Location');
            if ($location === '') {
                return [
                    'error' => 'fetch_failed',
                    'status' => $response->status(),
                    'message' => 'Redirect response without a Location header.',
                ];
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }

        if ($response === null || $response->redirect()) {
            return [
                'error' => 'too_many_redirects',
                'message' => 'Gave up after '.self::MAX_REDIRECTS.' redirects.',
            ];
        }

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
     * Structured error when the URL must not be fetched, null when it's safe.
     * Applied to the initial URL and to every redirect target.
     *
     * @return array<string, mixed>|null
     */
    private function rejectUrl(string $url, int $hop): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['port'])) {
            return [
                'error' => $hop === 0 ? 'host_not_allowed' : 'redirected_off_allowlist',
                'message' => 'Only plain http(s) URLs without explicit ports are fetchable.',
            ];
        }

        if (! in_array($host, self::ALLOWED_HOSTS, true)) {
            return $hop === 0
                ? [
                    'error' => 'host_not_allowed',
                    'message' => 'Host not in pricing-pages allowlist. Pick one of: '.implode(', ', self::ALLOWED_HOSTS),
                ]
                : [
                    'error' => 'redirected_off_allowlist',
                    'message' => 'Request redirected to '.$host.', which is not on the pricing-pages allowlist.',
                ];
        }

        return null;
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
                ->description('Absolute URL of a provider pricing page. Must be on the allowlist (developers.openai.com, claude.com, platform.claude.com, ai.google.dev, deepseek.com, x.ai, mistral.ai, groq.com, cohere.com, openrouter.ai or their docs subdomains).')
                ->required(),
        ];
    }
}
