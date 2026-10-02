<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Lines in app/Http/Controllers that may read an exception message without
 * UpstreamErrorText::sanitize(), keyed by file. Each one is app-authored
 * text written for the user, never reaches a response, or is local-only.
 *
 * @return array<string, list<string>>
 */
function upstreamEchoReviewedLines(): array
{
    return [
        'app/Http/Controllers/AI/ChatController.php' => [
            // App-authored budget / rate-limit / workflow refusals.
            "'message' => \$aiBudgetExceededException->getMessage(),",
            "'message' => \$rateLimitedException->getMessage(),",
            "return response()->json(['message' => \$workflowContinuationRefused->getMessage()], \$workflowContinuationRefused->status);",
            // Context array handed to Log::error(), never to the response.
            "'message' => \$throwable->getMessage(),",
            // Only when app()->isLocal().
            "\$payload['message'] = \$throwable->getMessage();",
        ],
        'app/Http/Controllers/Admin/AiModelCatalogController.php' => [
            // CatalogUnavailableException carries app-authored pricing-feed text.
            "return response()->json(['message' => \$catalogUnavailableException->getMessage()], 503);",
            "'models' => __('Could not load the pricing catalog: :message', ['message' => \$catalogUnavailableException->getMessage()]),",
        ],
        'app/Http/Controllers/Admin/ServiceConnectionController.php' => [
            // A QueryException inspected for a constraint name, never shown.
            "return str_contains(\$queryException->getMessage(), 'subtitle_cases_bazarr_connection_id_foreign')",
            "|| str_contains(\$queryException->getMessage(), 'subtitle_cases_service_connection_id_foreign');",
        ],
    ];
}

/**
 * @param  list<string>  $reviewedLines
 * @return list<string>
 */
function upstreamEchoOffenders(string $path, string $code, array $reviewedLines): array
{
    // A Log:: call's context array never reaches a response. [^;]* keeps a
    // context-free Log::info('x'); from swallowing the next statement.
    $code = (string) preg_replace('/Log::\w+\([^;]*\]\);/s', '', $code);
    $offenders = [];

    foreach (explode("\n", $code) as $line) {
        if (! str_contains($line, '->getMessage()')) {
            continue;
        }

        $trimmed = trim($line);

        if (str_contains($trimmed, 'UpstreamErrorText::sanitize(') || in_array($trimmed, $reviewedLines, true)) {
            continue;
        }

        $offenders[] = sprintf('%s: %s', $path, $trimmed);
    }

    return $offenders;
}

test('the echo scanner flags a raw message and ignores sanitized, logged and reviewed ones', function (): void {
    $code = <<<'PHP'
        Log::info('No context here.');
        return new JsonResponse(['error' => $throwable->getMessage()], 502);
        Log::warning('Logged only.', [
            'message' => $throwable->getMessage(),
        ]);
        Inertia::flash('toast', ['message' => UpstreamErrorText::sanitize($throwable->getMessage())]);
        'message' => $reviewedException->getMessage(),
        PHP;

    expect(upstreamEchoOffenders('app/Http/Controllers/Example.php', $code, ["'message' => \$reviewedException->getMessage(),"]))
        ->toBe(["app/Http/Controllers/Example.php: return new JsonResponse(['error' => \$throwable->getMessage()], 502);"]);
});

test('controllers never hand a raw exception message to the browser', function (): void {
    $root = dirname(__DIR__, 3);
    $reviewed = upstreamEchoReviewedLines();
    $offenders = [];
    $scanned = 0;

    foreach (Finder::create()->files()->in($root.'/app/Http/Controllers')->name('*.php') as $finder) {
        $path = str_replace($root.'/', '', $finder->getPathname());
        $scanned++;

        $offenders = [...$offenders, ...upstreamEchoOffenders($path, (string) file_get_contents($finder->getPathname()), $reviewed[$path] ?? [])];
    }

    // The scanner must actually walk the controller tree, or it proves nothing.
    expect($scanned)->toBeGreaterThan(50)
        ->and($offenders)->toBe([]);
});
