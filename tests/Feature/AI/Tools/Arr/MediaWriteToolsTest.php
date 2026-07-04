<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\AddMediaTool;
use App\Ai\Tools\Arr\DeleteMediaTool;
use App\Ai\Tools\Arr\MonitorMediaTool;
use App\Ai\Tools\Arr\SetMediaQualityProfileTool;
use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use Laravel\Ai\Tools\Request;

test('AddMediaTool queues an add_series ActionRequest for sonarr', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'add_series', 'is_enabled' => true, 'requires_approval' => true]);

    $result = json_decode((new AddMediaTool)->handle(new Request([
        'service' => 'sonarr',
        'remote_id' => 999001,
        'quality_profile_id' => 1,
        'root_folder_path' => '/tv',
        'monitored' => true,
        'season_folder' => true,
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['status'])->toBe(ActionRequestStatus::Pending->value);

    $ar = ActionRequest::firstWhere('type', 'add_series');
    expect($ar->target_service)->toBe('sonarr');
    expect($ar->payload)->toEqual([
        'tvdb_id' => 999001,
        'quality_profile_id' => 1,
        'root_folder_path' => '/tv',
        'monitored' => true,
        'season_folder' => true,
    ]);
});

test('AddMediaTool queues an add_movie ActionRequest for radarr', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'add_movie', 'is_enabled' => true, 'requires_approval' => true]);

    $result = json_decode((new AddMediaTool)->handle(new Request([
        'service' => 'radarr',
        'remote_id' => 27205,
        'quality_profile_id' => 1,
        'root_folder_path' => '/movies',
        'monitored' => true,
        'season_folder' => null,
    ])), true);

    expect($result['queued'])->toBeTrue();

    $ar = ActionRequest::firstWhere('type', 'add_movie');
    expect($ar->target_service)->toBe('radarr');
    expect($ar->payload)->toEqual([
        'tmdb_id' => 27205,
        'quality_profile_id' => 1,
        'root_folder_path' => '/movies',
        'monitored' => true,
    ]);
});

test('AddMediaTool queues a whisparr_add_item ActionRequest for whisparr', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'whisparr_add_item', 'is_enabled' => true, 'requires_approval' => true]);

    $result = json_decode((new AddMediaTool)->handle(new Request([
        'service' => 'whisparr',
        'remote_id' => 27205,
        'quality_profile_id' => 1,
        'root_folder_path' => '/data',
        'monitored' => true,
        'season_folder' => null,
    ])), true);

    expect($result['queued'])->toBeTrue();

    $ar = ActionRequest::firstWhere('type', 'whisparr_add_item');
    expect($ar->target_service)->toBe('whisparr');
    expect($ar->payload['tmdb_id'])->toBe(27205);
    expect($ar->payload)->not->toHaveKey('season_folder');
});

test('DeleteMediaTool queues a delete_series ActionRequest for sonarr', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'is_enabled' => true, 'requires_approval' => true]);

    $result = json_decode((new DeleteMediaTool)->handle(new Request([
        'service' => 'sonarr', 'item_id' => 42, 'delete_files' => true,
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['status'])->toBe(ActionRequestStatus::Pending->value);
    expect($result['requires_approval'])->toBeTrue();

    $ar = ActionRequest::firstWhere('type', 'delete_series');
    expect($ar->target_service)->toBe('sonarr');
    expect($ar->payload)->toEqual(['sonarr_series_id' => 42, 'delete_files' => true]);
});

test('DeleteMediaTool queues a delete_movie ActionRequest for radarr', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'delete_movie', 'is_enabled' => true, 'requires_approval' => true]);

    json_decode((new DeleteMediaTool)->handle(new Request([
        'service' => 'radarr', 'item_id' => 42, 'delete_files' => true,
    ])), true);

    $ar = ActionRequest::firstWhere('type', 'delete_movie');
    expect($ar->target_service)->toBe('radarr');
    expect($ar->payload)->toEqual(['radarr_movie_id' => 42, 'delete_files' => true]);
});

test('DeleteMediaTool queues a whisparr_delete_item ActionRequest for whisparr', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'whisparr_delete_item', 'is_enabled' => true, 'requires_approval' => true]);

    json_decode((new DeleteMediaTool)->handle(new Request([
        'service' => 'whisparr', 'item_id' => 9, 'delete_files' => true,
    ])), true);

    $ar = ActionRequest::firstWhere('type', 'whisparr_delete_item');
    expect($ar->payload)->toEqual(['whisparr_item_id' => 9, 'delete_files' => true]);
});

test('DeleteMediaTool reports no_action_type_config when the rule is missing', function (): void {
    $result = json_decode((new DeleteMediaTool)->handle(new Request([
        'service' => 'sonarr', 'item_id' => 42, 'delete_files' => true,
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('no_action_type_config');
});

test('MonitorMediaTool queues a monitor_series ActionRequest for sonarr', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'monitor_series', 'is_enabled' => true, 'requires_approval' => false]);

    $result = json_decode((new MonitorMediaTool)->handle(new Request([
        'service' => 'sonarr', 'item_id' => 42, 'monitored' => false,
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['status'])->toBe(ActionRequestStatus::Approved->value);

    $ar = ActionRequest::firstWhere('type', 'monitor_series');
    expect($ar->target_service)->toBe('sonarr');
    expect($ar->payload)->toEqual(['series_id' => 42, 'monitored' => false]);
});

test('MonitorMediaTool queues a monitor_movie ActionRequest for radarr', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'monitor_movie', 'is_enabled' => true, 'requires_approval' => false]);

    json_decode((new MonitorMediaTool)->handle(new Request([
        'service' => 'radarr', 'item_id' => 42, 'monitored' => false,
    ])), true);

    $ar = ActionRequest::firstWhere('type', 'monitor_movie');
    expect($ar->payload)->toEqual(['movie_id' => 42, 'monitored' => false]);
});

test('MonitorMediaTool queues a whisparr_monitor_item ActionRequest for whisparr', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'whisparr_monitor_item', 'is_enabled' => true, 'requires_approval' => false]);

    json_decode((new MonitorMediaTool)->handle(new Request([
        'service' => 'whisparr', 'item_id' => 9, 'monitored' => true,
    ])), true);

    $ar = ActionRequest::firstWhere('type', 'whisparr_monitor_item');
    expect($ar->payload)->toEqual(['whisparr_item_id' => 9, 'monitored' => true]);
});

test('SetMediaQualityProfileTool queues a set_series_quality_profile ActionRequest for sonarr', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'set_series_quality_profile', 'is_enabled' => true, 'requires_approval' => false]);

    $result = json_decode((new SetMediaQualityProfileTool)->handle(new Request([
        'service' => 'sonarr', 'item_id' => 42, 'quality_profile_id' => 7,
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['status'])->toBe(ActionRequestStatus::Approved->value);

    $ar = ActionRequest::firstWhere('type', 'set_series_quality_profile');
    expect($ar->payload)->toEqual(['series_id' => 42, 'quality_profile_id' => 7]);
});

test('SetMediaQualityProfileTool queues a set_movie_quality_profile ActionRequest for radarr', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'set_movie_quality_profile', 'is_enabled' => true, 'requires_approval' => false]);

    json_decode((new SetMediaQualityProfileTool)->handle(new Request([
        'service' => 'radarr', 'item_id' => 42, 'quality_profile_id' => 7,
    ])), true);

    $ar = ActionRequest::firstWhere('type', 'set_movie_quality_profile');
    expect($ar->payload)->toEqual(['movie_id' => 42, 'quality_profile_id' => 7]);
});

test('SetMediaQualityProfileTool queues a whisparr_set_quality_profile ActionRequest for whisparr', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'whisparr_set_quality_profile', 'is_enabled' => true, 'requires_approval' => false]);

    json_decode((new SetMediaQualityProfileTool)->handle(new Request([
        'service' => 'whisparr', 'item_id' => 9, 'quality_profile_id' => 7,
    ])), true);

    $ar = ActionRequest::firstWhere('type', 'whisparr_set_quality_profile');
    expect($ar->payload)->toEqual(['whisparr_item_id' => 9, 'quality_profile_id' => 7]);
});

test('unknown service returns tool_failed for every write tool', function (): void {
    foreach ([new AddMediaTool, new DeleteMediaTool, new MonitorMediaTool, new SetMediaQualityProfileTool] as $tool) {
        $result = json_decode($tool->handle(new Request(['service' => 'emby', 'item_id' => 1])), true);

        expect($result['error'])->toBe('tool_failed');
    }

    expect(ActionRequest::count())->toBe(0);
});

test('all write tools are Destructive', function (): void {
    expect((new AddMediaTool)->risk())->toBe(Risk::Destructive);
    expect((new DeleteMediaTool)->risk())->toBe(Risk::Destructive);
    expect((new MonitorMediaTool)->risk())->toBe(Risk::Destructive);
    expect((new SetMediaQualityProfileTool)->risk())->toBe(Risk::Destructive);
});
