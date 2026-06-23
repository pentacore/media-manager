<?php

declare(strict_types=1);

use App\Ai\Tools\Whisparr\AddItemTool;
use App\Ai\Tools\Whisparr\DeleteItemTool;
use App\Ai\Tools\Whisparr\GetItemTool;
use App\Enums\WhisparrVersion;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

test('AddItemTool emits a whisparr_add_item descriptor', function (): void {
    $tool = new AddItemTool;
    $reflection = new ReflectionMethod($tool, 'execute');
    $result = $reflection->invoke($tool, new Request(['tmdb_id' => 27205, 'quality_profile_id' => 1, 'root_folder_path' => '/data', 'monitored' => true]));

    expect($result['type'])->toBe('whisparr_add_item');
    expect($result['target_service'])->toBe('whisparr');
    expect($result['payload']['tmdb_id'])->toBe(27205);
});

test('DeleteItemTool emits a whisparr_delete_item descriptor', function (): void {
    $tool = new DeleteItemTool;
    $reflection = new ReflectionMethod($tool, 'execute');
    $result = $reflection->invoke($tool, new Request(['whisparr_item_id' => 9, 'delete_files' => true]));

    expect($result['type'])->toBe('whisparr_delete_item');
    expect($result['payload']['whisparr_item_id'])->toBe(9);
});

test('GetItemTool reads from the active Whisparr connection', function (): void {
    Http::preventStrayRequests();
    Http::fake(['whisparr.local:6969/api/v3/movie' => Http::response([['id' => 1, 'title' => 'X']])]);
    ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'k', 'is_active' => true,
    ]);

    $tool = new GetItemTool;
    $reflection = new ReflectionMethod($tool, 'execute');
    $result = $reflection->invoke($tool, new Request(['whisparr_item_id' => null]));

    expect($result)->toHaveCount(1);
});
