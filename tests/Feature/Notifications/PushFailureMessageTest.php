<?php

declare(strict_types=1);

use App\Services\Notifications\PushFailureMessage;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

test('a connection failure is summarised so the request uri never leaks', function (): void {
    $message = PushFailureMessage::for(new ConnectionException(
        'cURL error 6: Could not resolve host (see https://curl.se/libcurl/c/libcurl-errors.html) for https://api.telegram.org/bot123:abc/sendMessage'
    ));

    expect($message)->toBe('Could not reach the provider.')
        ->and($message)->not->toContain('123:abc');
});

test('an http failure is reduced to its status code', function (): void {
    $exception = new RequestException(new Response(new PsrResponse(404, [], 'nope')));

    expect(PushFailureMessage::for($exception))->toBe('Provider responded with HTTP 404.');
});

test('any other failure keeps its message with the telegram token redacted', function (): void {
    config()->set('services.telegram.token', '123:abc');

    expect(PushFailureMessage::for(new RuntimeException('token 123:abc leaked')))->toBe('token *** leaked');
});
