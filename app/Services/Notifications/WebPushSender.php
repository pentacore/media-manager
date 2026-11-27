<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use GuzzleHttp\Client;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends one encrypted Web Push message to one browser subscription with the
 * server's VAPID identity. Bound in the container so tests can swap it.
 */
class WebPushSender
{
    public function __construct(private readonly WebPushConfig $config) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function send(PushSubscription $subscription, string $payload, array $options): WebPushResult
    {
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $this->config->subject(),
                'publicKey' => (string) $this->config->publicKey(),
                'privateKey' => (string) $this->config->privateKey(),
            ],
        ], [], new Client([
            'allow_redirects' => false,
            'timeout' => 10,
            'connect_timeout' => 5,
        ]));

        if (! $this->config->allowsEndpoint($subscription->endpoint)) {
            return new WebPushResult(false, true, 'Endpoint host is not allowed');
        }

        $webPush->queueNotification(
            new Subscription(
                $subscription->endpoint,
                $subscription->public_key,
                $subscription->auth_token,
                $subscription->content_encoding,
            ),
            $payload,
            $options,
        );

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                return new WebPushResult(true, false, null);
            }

            $status = $report->getResponse()?->getStatusCode();

            return new WebPushResult(false, in_array($status, [404, 410], true), $report->getReason());
        }

        return new WebPushResult(false, false, 'No report from the push service');
    }
}
