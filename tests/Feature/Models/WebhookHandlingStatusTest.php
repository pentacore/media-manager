<?php

declare(strict_types=1);

use App\Enums\WebhookHandlingStatus;
use App\Models\WebhookEvent;

test('handling_status casts to the enum', function (): void {
    $event = WebhookEvent::factory()->create(['handling_status' => WebhookHandlingStatus::Handled]);

    expect($event->refresh()->handling_status)->toBe(WebhookHandlingStatus::Handled);
});

test('handling_status defaults to null', function (): void {
    expect(WebhookEvent::factory()->create()->handling_status)->toBeNull();
});

test('every case has a label', function (): void {
    foreach (WebhookHandlingStatus::cases() as $case) {
        expect($case->label())->toBeString()->not->toBe('');
    }
});
