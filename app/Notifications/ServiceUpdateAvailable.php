<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ServiceConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
        return ['mail'];
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
