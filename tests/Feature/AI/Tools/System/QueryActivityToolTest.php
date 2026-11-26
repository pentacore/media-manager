<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\System\QueryActivityTool;
use App\Models\ActivityLog;
use App\Models\EmbyActivity;
use App\Models\User;
use Laravel\Ai\Tools\Request;

test('emby scope returns recent playback entries', function (): void {
    EmbyActivity::factory()->count(3)->create();

    $tool = new QueryActivityTool;

    $result = json_decode($tool->handle(new Request(['scope' => 'emby', 'since_days' => null, 'media_type' => null, 'limit' => null])), true);

    expect($result['scope'])->toBe('emby');
    expect($result['entries'])->toHaveCount(3);
});

test('system scope returns recent activity logs', function (): void {
    ActivityLog::factory()->count(2)->create();

    $tool = new QueryActivityTool;

    $result = json_decode($tool->handle(new Request(['scope' => 'system', 'since_days' => null, 'media_type' => null, 'limit' => null])), true);

    expect($result['scope'])->toBe('system');
    expect($result['entries'])->toHaveCount(2);
});

/**
 * @return list<int>
 */
function queryActivitySystemIds(): array
{
    $result = json_decode((new QueryActivityTool)->handle(new Request(['scope' => 'system', 'since_days' => null, 'media_type' => null, 'limit' => null])), true);

    return array_column($result['entries'], 'id');
}

test('a viewer in chat sees only their own activity rows and no audit rows', function (): void {
    $viewer = User::factory()->create();
    $own = ActivityLog::factory()->for($viewer)->create();
    ActivityLog::factory()->audit()->for($viewer)->create();
    ActivityLog::factory()->create();

    $this->actingAs($viewer);

    expect(queryActivitySystemIds())->toBe([$own->id]);
});

test("a member in chat sees every user's activity rows and no audit rows", function (): void {
    $activity = ActivityLog::factory()->count(2)->create();
    ActivityLog::factory()->audit()->create();

    $this->actingAs(User::factory()->member()->create());

    expect(queryActivitySystemIds())->toEqualCanonicalizing($activity->modelKeys());
});

test('an admin in chat still gets no audit rows', function (): void {
    $activity = ActivityLog::factory()->create();
    ActivityLog::factory()->audit()->create();

    $this->actingAs(User::factory()->admin()->create());

    expect(queryActivitySystemIds())->toBe([$activity->id]);
});

test('without a user every activity row and no audit row is returned', function (): void {
    $activity = ActivityLog::factory()->count(2)->create();
    ActivityLog::factory()->audit()->create();

    expect(queryActivitySystemIds())->toEqualCanonicalizing($activity->modelKeys());
});

test('risk is Read', function (): void {
    expect((new QueryActivityTool)->risk())->toBe(Risk::Read);
});
