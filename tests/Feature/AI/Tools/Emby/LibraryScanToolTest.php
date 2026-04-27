<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Emby\LibraryScanTool;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use Laravel\Ai\Tools\Request;

test('queues an emby_library_scan ActionRequest', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'emby_library_scan',
        'is_enabled' => true,
        'requires_approval' => false,
    ]);

    $result = json_decode((string) (new LibraryScanTool)->handle(new Request([])), true);

    expect($result['queued'])->toBeTrue();

    $ar = ActionRequest::firstWhere('type', 'emby_library_scan');
    expect($ar->target_service)->toBe('emby');
});

test('risk is Destructive', function (): void {
    expect((new LibraryScanTool)->risk())->toBe(Risk::Destructive);
});
