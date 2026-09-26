<?php

declare(strict_types=1);

use App\Events\ActionRequestCreated;
use App\Models\ActionRequest;
use Illuminate\Support\Facades\Event;

test('every demo action is created with a verified description', function (): void {
    Event::fake([ActionRequestCreated::class]);

    $this->artisan('demo:fake-actions', ['--delay' => 0, '--count' => 20])->assertSuccessful();

    expect(ActionRequest::query()->pluck('description_verified')->unique()->values()->all())->toBe([true]);
    Event::assertDispatched(fn (ActionRequestCreated $actionRequestCreated): bool => $actionRequestCreated->actionRequest->description_verified === true);
    Event::assertNotDispatched(ActionRequestCreated::class, fn (ActionRequestCreated $actionRequestCreated): bool => $actionRequestCreated->actionRequest->description_verified !== true);
});

test('the demo seerr cleanup targets the request with the key the describer and executor read', function (): void {
    Event::fake([ActionRequestCreated::class]);

    $this->artisan('demo:fake-actions', ['--delay' => 0, '--count' => 20])->assertSuccessful();

    expect(ActionRequest::query()->where('type', 'cleanup_seerr_request')->sole()->payload)
        ->toHaveKey('seerr_request_id', 5099)
        ->not->toHaveKey('request_id');
});
