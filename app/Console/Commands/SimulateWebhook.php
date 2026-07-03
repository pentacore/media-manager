<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Override;

class SimulateWebhook extends Command
{
    #[Override]
    protected $signature = 'webhook:simulate
        {service? : Service type (emby, sonarr, radarr, seerr, whisparr)}
        {event? : Event name, e.g. playback.start}
        {--connection= : Service connection id (defaults to first active of that service type)}
        {--set=* : Override a payload key, e.g. --set User.Id=abc123}
        {--dry-run : Print the payload without firing}
        {--list : List available fixtures}';

    #[Override]
    protected $description = 'Fire a realistic webhook fixture at our own webhook endpoint for local testing.';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listFixtures();
        }

        $service = (string) ($this->argument('service') ?? '');
        $event = (string) ($this->argument('event') ?? '');

        if ($service === '' || $event === '') {
            $this->error('Both "service" and "event" arguments are required. Use --list to see available fixtures.');

            return self::FAILURE;
        }

        $serviceType = ServiceType::tryFrom($service);
        if (! $serviceType instanceof ServiceType) {
            $this->error(sprintf(
                'Unknown service "%s". Valid services: %s',
                $service,
                implode(', ', array_map(static fn (ServiceType $serviceType): string => $serviceType->value, ServiceType::cases())),
            ));

            return self::FAILURE;
        }

        $fixturePath = $this->fixturePath($service, $event);
        if (! is_file($fixturePath)) {
            $this->error(sprintf('No fixture found at %s', $fixturePath));
            $this->line('Run with --list to see available fixtures.');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);

        $payload = $this->applyOverrides($payload);

        $connection = $this->resolveConnection($serviceType);
        if (! $connection instanceof ServiceConnection) {
            $this->error(sprintf('No active %s connection found. Create one (or pass --connection=ID).', $serviceType->label()));

            return self::FAILURE;
        }

        $url = rtrim((string) config('app.url'), '/').'/api/webhooks/'.$service.'/'.$connection->id;

        if ($this->option('dry-run')) {
            $this->info('Dry run — payload not sent.');
            $this->line('Target URL: '.$url);
            $this->line('Connection: #'.$connection->id.' ('.$connection->name.')');
            $this->line('X-Webhook-Token: '.$connection->webhook_token);
            $this->line('Payload:');

            $payloadLines = explode("\n", (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            foreach ($payloadLines as $payloadLine) {
                $this->line($payloadLine);
            }

            return self::SUCCESS;
        }

        $firedAt = Date::now()->subSecond();

        $response = Http::withHeaders(['X-Webhook-Token' => $connection->webhook_token])
            ->post($url, $payload);

        if ($response->failed()) {
            $this->error(sprintf('Webhook POST failed with status %d: %s', $response->status(), $response->body()));

            return self::FAILURE;
        }

        $this->info(sprintf('Webhook delivered (HTTP %d).', $response->status()));

        $this->reportOutcome($connection, $firedAt);

        return self::SUCCESS;
    }

    private function listFixtures(): int
    {
        $root = $this->fixturesRoot();

        if (! is_dir($root)) {
            $this->warn('No fixtures directory found at '.$root);

            return self::SUCCESS;
        }

        $services = array_values(array_filter(
            scandir($root) ?: [],
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($root.DIRECTORY_SEPARATOR.$entry),
        ));

        if ($services === []) {
            $this->warn('No fixtures found under '.$root);

            return self::SUCCESS;
        }

        sort($services);

        $this->info('Available webhook fixtures:');
        foreach ($services as $service) {
            $this->line('  '.$service);

            $serviceDir = $root.DIRECTORY_SEPARATOR.$service;
            $files = array_values(array_filter(
                scandir($serviceDir) ?: [],
                static fn (string $entry): bool => str_ends_with($entry, '.json'),
            ));
            sort($files);

            foreach ($files as $file) {
                $event = substr($file, 0, -strlen('.json'));
                $this->line('    - '.$event);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyOverrides(array $payload): array
    {
        /** @var array<int, string> $overrides */
        $overrides = (array) $this->option('set');

        foreach ($overrides as $override) {
            if (! str_contains($override, '=')) {
                $this->warn(sprintf('Ignoring malformed --set "%s" (expected key=value).', $override));

                continue;
            }

            [$key, $value] = explode('=', $override, 2);
            Arr::set($payload, $key, $this->coerceValue($value));
        }

        return $payload;
    }

    private function coerceValue(string $value): mixed
    {
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => is_numeric($value) ? $value + 0 : $value,
        };
    }

    private function resolveConnection(ServiceType $serviceType): ?ServiceConnection
    {
        $explicit = $this->option('connection');
        if ($explicit !== null && $explicit !== '') {
            return ServiceConnection::query()
                ->where('id', (int) $explicit)
                ->where('type', $serviceType)
                ->first();
        }

        return ServiceConnection::query()
            ->where('type', $serviceType)
            ->where('is_active', true)
            ->first();
    }

    private function reportOutcome(ServiceConnection $serviceConnection, CarbonInterface $firedAt): void
    {
        $webhookEvent = WebhookEvent::query()
            ->where('service_connection_id', $serviceConnection->id)
            ->latest('id')
            ->first();

        if (! $webhookEvent instanceof WebhookEvent) {
            $this->warn('No WebhookEvent row found after firing — did the middleware reject it?');

            return;
        }

        $this->line('');
        $this->line('WebhookEvent:');
        $this->table(
            ['id', 'event_type', 'processed_at', 'created_at'],
            [[
                (string) $webhookEvent->id,
                (string) $webhookEvent->event_type,
                $webhookEvent->processed_at?->toIso8601String() ?? '(queued)',
                $webhookEvent->created_at?->toIso8601String() ?? '',
            ]],
        );

        $actionRequests = ActionRequest::query()
            ->where('created_at', '>=', $firedAt)
            ->orderBy('id')
            ->get();

        if ($actionRequests->isEmpty()) {
            $this->line('No ActionRequest rows were created (handler may have logged-and-skipped, or is queued).');

            return;
        }

        $this->line('');
        $this->line('ActionRequests:');
        $this->table(
            ['id', 'type', 'source', 'target', 'status', 'requires_approval'],
            $actionRequests->map(static fn (ActionRequest $actionRequest): array => [
                (string) $actionRequest->id,
                $actionRequest->type,
                $actionRequest->source_service,
                $actionRequest->target_service,
                $actionRequest->status->value,
                $actionRequest->requires_approval ? 'yes' : 'no',
            ])->all(),
        );
    }

    private function fixturePath(string $service, string $event): string
    {
        return $this->fixturesRoot().DIRECTORY_SEPARATOR.$service.DIRECTORY_SEPARATOR.$event.'.json';
    }

    private function fixturesRoot(): string
    {
        return database_path('fixtures'.DIRECTORY_SEPARATOR.'webhooks');
    }
}
