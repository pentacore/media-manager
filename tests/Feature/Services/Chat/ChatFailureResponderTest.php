<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\Chat\ChatFailureResponder;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\RateLimitedException;

test('an expected agent failure maps to the status, code and message the chat explains', function (Closure $makeThrowable, int $status, string $error, string $messageFragment): void {
    $jsonResponse = resolve(ChatFailureResponder::class)->respond($makeThrowable(), User::factory()->admin()->create());

    expect($jsonResponse->getStatusCode())->toBe($status)
        ->and($jsonResponse->getData(true)['error'])->toBe($error)
        ->and($jsonResponse->getData(true)['message'])->toContain($messageFragment);
})->with([
    "a rate limit, ours or the provider's" => [fn (): RateLimitedException => RateLimitedException::forProvider('openai'), 429, 'rate_limited', 'rate limited'],
    'the hard cap reached mid-run' => [fn (): AiBudgetExceededException => new AiBudgetExceededException(101.0, 100.0), 402, 'budget_exceeded', 'Monthly AI hard cap reached'],
    'an unreachable provider' => [fn (): ProviderConnectionException => new ProviderConnectionException('connect timeout'), 503, 'provider_unreachable', "Couldn't reach the AI provider."],
]);

test('any other failure is a generic 500 that hides its message outside local and logs it in full', function (): void {
    Log::spy();
    $this->app['env'] = 'production';
    $admin = User::factory()->admin()->create();

    $jsonResponse = resolve(ChatFailureResponder::class)->respond(new RuntimeException('EXAMPLE-SECRET'), $admin);

    expect($jsonResponse->getStatusCode())->toBe(500)
        ->and($jsonResponse->getData(true))->toBe(['error' => 'AI request failed.']);

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'AI request failed.'
            && $context['user_id'] === $admin->id
            && $context['exception'] === RuntimeException::class
            && $context['message'] === 'EXAMPLE-SECRET'
    );
});

test('a local 500 carries the failure message for debugging', function (): void {
    $this->app['env'] = 'local';

    $jsonResponse = resolve(ChatFailureResponder::class)->respond(new RuntimeException('Example local detail'), null);

    expect($jsonResponse->getData(true))->toBe(['error' => 'AI request failed.', 'message' => 'Example local detail']);
});

test('a failed provider call logs the whole response body and status', function (): void {
    Log::spy();
    $this->app['env'] = 'production';
    $requestException = new RequestException(new Response(new Psr7Response(400, [], '{"error":{"message":"Example bad key"}}')));

    resolve(ChatFailureResponder::class)->respond($requestException, null);

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $context['response_status'] === 400
            && $context['response_body'] === '{"error":{"message":"Example bad key"}}'
    );
});

test('the pre-flight refusals answer with the same codes as a refused run', function (): void {
    $chatFailureResponder = resolve(ChatFailureResponder::class);

    $rateLimited = $chatFailureResponder->rateLimited(RateLimitedException::forProvider('openai'));
    $budgetExceeded = $chatFailureResponder->budgetExceeded(new AiBudgetExceededException(101.0, 100.0));

    expect($rateLimited->getStatusCode())->toBe(429)
        ->and($rateLimited->getData(true)['error'])->toBe('rate_limited')
        ->and($budgetExceeded->getStatusCode())->toBe(402)
        ->and($budgetExceeded->getData(true)['error'])->toBe('budget_exceeded')
        ->and($budgetExceeded->getData(true)['message'])->toContain('Monthly AI hard cap reached');
});
