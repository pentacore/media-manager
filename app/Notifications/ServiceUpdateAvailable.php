<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ServiceConnection;
use App\Services\Notifications\NtfyMessage;
use App\Services\Notifications\PreferenceResolver;
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

    /**
     * @return array{title: string, message: string, priority: int, tags: array<int, string>, click?: string}
     */
    public function toNtfy(object $notifiable): array
    {
        return NtfyMessage::for(
            'info',
            sprintf('Update available for %s', $this->serviceConnection->name),
            sprintf('%s → %s', $this->currentVersion ?? 'unknown', $this->latestVersion),
            route('monitoring.service-health'),
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
