<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function (): void {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function (): void {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('registration is closed once a user exists', function (): void {
    User::factory()->create();

    $this->get(route('register'))->assertNotFound();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();

    expect(User::count())->toBe(1);
    $this->assertGuest();
});

test('registration can be enabled by config', function (): void {
    config(['mediamanager.registration_enabled' => true]);
    User::factory()->create();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('login page hides the register link when registration is closed', function (): void {
    User::factory()->create();

    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('canRegister', false));
});

test('welcome page offers registration while no user exists', function (): void {
    $this->get(route('home'))->assertInertia(fn ($page) => $page
        ->component('Welcome')
        ->where('canRegister', true));
});

test('welcome page hides registration once a user exists', function (): void {
    User::factory()->create();

    $this->get(route('home'))->assertInertia(fn ($page) => $page
        ->component('Welcome')
        ->where('canRegister', false));
});
