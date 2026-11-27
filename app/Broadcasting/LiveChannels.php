<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Enums\ServiceType;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * The one place that builds the names of the scoped live-update channels, so an
 * event and its channel rule in routes/channels.php cannot drift apart.
 */
final class LiveChannels
{
    public static function dashboard(): PrivateChannel
    {
        return new PrivateChannel('dashboard');
    }

    public static function webhooks(ServiceType $serviceType): PrivateChannel
    {
        return new PrivateChannel('webhooks.'.$serviceType->value);
    }

    public static function webhookConnection(ServiceType $serviceType, int $connectionId): PrivateChannel
    {
        return new PrivateChannel(sprintf('webhooks.%s.%d', $serviceType->value, $connectionId));
    }

    public static function servers(): PrivateChannel
    {
        return new PrivateChannel('admin.servers');
    }

    public static function server(int $serverId): PrivateChannel
    {
        return new PrivateChannel('admin.servers.'.$serverId);
    }

    public static function mediaReplacement(): PrivateChannel
    {
        return new PrivateChannel('admin.media-replacement');
    }

    public static function mediaReplacementAttempt(int $attemptId): PrivateChannel
    {
        return new PrivateChannel('admin.media-replacement.'.$attemptId);
    }

    public static function user(int $userId): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$userId);
    }
}
