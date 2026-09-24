<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Seerr\ApproveRequestTool;
use App\Ai\Tools\Seerr\DeclineRequestTool;
use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('ApproveRequestTool queues an approve_seerr_request ActionRequest', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'approve_seerr_request',
        'is_enabled' => true,
        'requires_approval' => false,
    ]);
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055']);
    Http::fake([
        'seerr.local:5055/api/v1/request/5101' => Http::response(['type' => 'movie', 'media' => ['tmdbId' => 438631]]),
        'seerr.local:5055/api/v1/movie/438631' => Http::response(['title' => 'Dune', 'releaseDate' => '2021-10-22']),
    ]);

    $result = json_decode((new ApproveRequestTool)->handle(new Request([
        'seerr_request_id' => 5101,
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['status'])->toBe(ActionRequestStatus::Approved->value);

    $ar = ActionRequest::firstWhere('type', 'approve_seerr_request');
    expect($ar->target_service)->toBe('seerr');
    expect($ar->payload)->toEqual(['seerr_request_id' => 5101]);
    expect($ar->title)->toBe('Approve Seerr request for "Dune (2021)"');
});

test('DeclineRequestTool queues a decline_seerr_request ActionRequest', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'decline_seerr_request',
        'is_enabled' => true,
        'requires_approval' => false,
    ]);

    $result = json_decode((new DeclineRequestTool)->handle(new Request([
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
