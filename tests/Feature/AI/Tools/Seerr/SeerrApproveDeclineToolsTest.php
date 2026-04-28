<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Seerr\ApproveRequestTool;
use App\Ai\Tools\Seerr\DeclineRequestTool;
use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use Laravel\Ai\Tools\Request;

test('ApproveRequestTool queues an approve_seerr_request ActionRequest', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'approve_seerr_request',
        'is_enabled' => true,
        'requires_approval' => false,
    ]);

    $result = json_decode((string) (new ApproveRequestTool)->handle(new Request([
        'seerr_request_id' => 5101,
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['status'])->toBe(ActionRequestStatus::Approved->value);

    $ar = ActionRequest::firstWhere('type', 'approve_seerr_request');
    expect($ar->target_service)->toBe('seerr');
    expect($ar->payload)->toEqual(['seerr_request_id' => 5101]);
});

test('DeclineRequestTool queues a decline_seerr_request ActionRequest', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'decline_seerr_request',
        'is_enabled' => true,
        'requires_approval' => false,
    ]);

    $result = json_decode((string) (new DeclineRequestTool)->handle(new Request([
        'seerr_request_id' => 5102,
    ])), true);

    expect($result['queued'])->toBeTrue();

    $ar = ActionRequest::firstWhere('type', 'decline_seerr_request');
    expect($ar->payload)->toEqual(['seerr_request_id' => 5102]);
});

test('ApproveRequestTool risk is Destructive', function (): void {
    expect((new ApproveRequestTool)->risk())->toBe(Risk::Destructive);
});

test('DeclineRequestTool risk is Destructive', function (): void {
    expect((new DeclineRequestTool)->risk())->toBe(Risk::Destructive);
});
