<?php

declare(strict_types=1);

use App\Models\PushSubscription;
use App\Services\Notifications\WebPushResult;
use App\Services\Notifications\WebPushSender;

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
