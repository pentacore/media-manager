<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\User;

test('the dashboard lists pending approvals by title with their description', function (): void {
    $this->actingAs(User::factory()->member()->create());
    ActionRequest::factory()->described()->create();

    visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-pending-approval-title]', 'Delete series "Severance (2022)"')
        ->assertSeeIn('[data-pending-approval-description]', 'Sonarr will delete the series');
});
