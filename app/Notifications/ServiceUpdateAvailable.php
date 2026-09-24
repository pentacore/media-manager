<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ServiceConnection;
use App\Services\Notifications\PreferenceResolver;
use App\Services\Notifications\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ServiceUpdateAvailable extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ServiceConnection $serviceConnection,
        public string $latestVersion,
        public ?string $currentVersion,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return resolve(PreferenceResolver::class)
            ->channelsFor($notifiable, self::class, 'info');
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage(
            severity: 'info',
            title: sprintf('Update available for %s', $this->serviceConnection->name),
            body: sprintf('%s → %s', $this->currentVersion ?? 'unknown', $this->latestVersion),
            url: route('monitoring.service-health'),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->serviceConnection->name;
        $type = $this->serviceConnection->type->value;

        return (new MailMessage)
            ->subject(sprintf('[%s] Update available for %s', config('app.name'), $name))
            ->line(sprintf('A new release is available for your %s connection "%s".', $type, $name))
            ->line(sprintf('Current version: %s', $this->currentVersion ?? 'unknown'))
            ->line(sprintf('Latest version: %s', $this->latestVersion))
            ->line('Update at your convenience to pick up the latest fixes.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'service_connection_id' => $this->serviceConnection->id,
            'service_name' => $this->serviceConnection->name,
            'service_type' => $this->serviceConnection->type->value,
            'current_version' => $this->currentVersion,
            'latest_version' => $this->latestVersion,
        ];
    }
}
