<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use GuzzleHttp\Client;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Throwable;

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
        if (! $this->config->allowsEndpoint($subscription->endpoint)) {
            return new WebPushResult(false, true, 'Endpoint host is not allowed');
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $this->config->subject(),
                    'publicKey' => (string) $this->config->publicKey(),
                    'privateKey' => (string) $this->config->privateKey(),
                ],
            ], [], $this->client());

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
                $status = $report->getResponse()?->getStatusCode();

                if ($report->isSuccess() && $status !== null && $status >= 200 && $status < 300) {
                    return new WebPushResult(true, false, null);
                }

                $reason = $report->isSuccess() ? 'Push service responded with status code '.$status : $report->getReason();

                return new WebPushResult(false, in_array($status, [404, 410], true), $this->redact($reason));
            }

            return new WebPushResult(false, false, 'No report from the push service');
        } catch (Throwable $throwable) {
            // One bad device (oversized payload, malformed key) must not stop the rest.
            return new WebPushResult(false, false, $this->redact($throwable->getMessage() !== '' ? $throwable->getMessage() : $throwable::class));
        }
    }

    /**
     * Redirects stay off so a push service can never bounce the request to
     * another host.
     *
     * @return array<string, mixed>
     */
    protected function clientConfig(): array
    {
        return [
            'allow_redirects' => false,
            'timeout' => 10,
            'connect_timeout' => 5,
        ];
    }

    protected function client(): ClientInterface
    {
        return new Client($this->clientConfig());
    }

    /**
     * Guzzle and the library put the endpoint URL (which identifies a
     * browser) into their messages; it must not reach logs or flashes.
     */
    private function redact(string $reason): string
    {
        $reason = preg_replace('#[a-z][a-z0-9+.-]*://\S+#i', '[endpoint]', $reason) ?? '';

        return mb_substr(trim($reason), 0, 300);
    }
}
