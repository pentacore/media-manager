<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubReleaseClient
{
    private const string BASE_URL = 'https://api.github.com';

    /**
     * Fetch the latest release tag for a GitHub repo ("owner/name" form).
     * Returns the tag with any leading "v" stripped, or null on failure.
     */
    public function latestRelease(string $repo): ?string
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'MediaManager',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        $token = config('services.github.token');

        if (is_string($token) && $token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->withHeaders($headers)
                ->timeout(10)
                ->connectTimeout(3)
                ->retry(2, 500, throw: false)
                ->get(sprintf('/repos/%s/releases/latest', $repo));
        } catch (RequestException|ConnectionException $e) {
            Log::warning('GitHubReleaseClient: request failed', [
                'repo' => $repo,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::info('GitHubReleaseClient: non-successful response', [
                'repo' => $repo,
                'status' => $response->status(),
            ]);

            return null;
        }

        $tag = $response->json('tag_name');

        if (! is_string($tag) || $tag === '') {
            return null;
        }

        return ltrim($tag, 'vV');
    }
}
