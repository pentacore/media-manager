<?php

declare(strict_types=1);

use App\Models\PushSubscription;
use App\Services\Notifications\WebPushConfig;
use App\Services\Notifications\WebPushResult;
use App\Services\Notifications\WebPushSender;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use Psr\Http\Client\ClientInterface;

/**
 * @param  array<string, WebPushResult>  $results  keyed by endpoint
 */
function fakeWebPushSender(array $results = []): object
{
    $fake = new class($results) extends WebPushSender
    {
        /** @var list<array{endpoint: string, payload: string, options: array<string, mixed>}> */
        public array $sent = [];

        /** @param array<string, WebPushResult> $results */
        public function __construct(private readonly array $results) {}

        public function send(PushSubscription $subscription, string $payload, array $options): WebPushResult
        {
            $this->sent[] = ['endpoint' => $subscription->endpoint, 'payload' => $payload, 'options' => $options];

            return $this->results[$subscription->endpoint] ?? new WebPushResult(true, false, null);
        }
    };

    app()->instance(WebPushSender::class, $fake);

    return $fake;
}

function base64UrlEncode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * A fresh P-256 device public key (uncompressed, base64url) and auth secret.
 *
 * @return array{string, string}
 */
function deviceKeys(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ec = openssl_pkey_get_details($key)['ec'];
    $public = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

    return [base64UrlEncode($public), base64UrlEncode(random_bytes(16))];
}

/**
 * A real sender whose HTTP layer is a Guzzle MockHandler. The handler also
 * records the request options Guzzle ends up passing to it.
 *
 * @param  list<Response|Throwable>  $responses
 */
function senderWith(array $responses, ?array &$options = null, ?string &$requestedUrl = null): WebPushSender
{
    $vapid = VAPID::createVapidKeys();
    $config = new WebPushConfig($vapid['publicKey'], $vapid['privateKey'], 'mailto:ops@example.test', ['push.example.test']);
    app()->instance(WebPushConfig::class, $config);

    $queue = array_map(function (Response|Throwable $response) use (&$options, &$requestedUrl): Closure {
        return function ($request, array $given) use ($response, &$options, &$requestedUrl): PromiseInterface {
            $options = $given;
            $requestedUrl = (string) $request->getUri();

            return $response instanceof Throwable ? Create::rejectionFor($response) : Create::promiseFor($response);
        };
    }, $responses);

    return new class($config, new MockHandler($queue)) extends WebPushSender
    {
        public function __construct(WebPushConfig $config, private readonly MockHandler $mock)
        {
            parent::__construct($config);
        }

        protected function client(): ClientInterface
        {
            return new Client(['handler' => HandlerStack::create($this->mock)] + $this->clientConfig());
        }
    };
}
