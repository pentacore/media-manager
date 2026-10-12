<?php

declare(strict_types=1);

use App\Models\User;
use App\Settings\AiSettings;

test('the chat timeout field keeps the saved value and explains the server cap', function (): void {
    config()->set('octane.max_execution_time', 60);
    resolve(AiSettings::class)->setChatTimeout(300);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->assertValue('input[name="chat_timeout"]', '300')
        ->assertSeeIn('[data-chat-timeout-effective]', 'Effective: 50 s')
        ->click('Save settings')
        ->assertSee('AI settings updated.')
        ->assertNoSmoke();

    // Saving the untouched form must not persist the cap.
    expect(resolve(AiSettings::class)->configuredChatTimeout())->toBe(300);
});

test('the chat timeout field shows no cap note when the server has no ceiling', function (): void {
    config()->set('octane.max_execution_time', 0);
    resolve(AiSettings::class)->setChatTimeout(300);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->assertValue('input[name="chat_timeout"]', '300')
        ->assertMissing('[data-chat-timeout-effective]');
});
