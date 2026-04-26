<?php

declare(strict_types=1);

use App\Models\AppSetting;
use App\Settings\AppSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

test('get returns default when key is missing', function (): void {
    $value = resolve(AppSettings::class)->get('missing.key', 'fallback');

    expect($value)->toBe('fallback');
});

test('set persists value and round-trips', function (): void {
    $appSettings = resolve(AppSettings::class);

    $appSettings->set('foo.bar', 'baz');

    expect($appSettings->get('foo.bar'))->toBe('baz');
    $this->assertDatabaseHas('app_settings', ['key' => 'foo.bar']);
});

test('set invalidates the cache so updates are immediately visible', function (): void {
    $appSettings = resolve(AppSettings::class);

    $appSettings->set('greeting', 'hello');

    expect($appSettings->get('greeting'))->toBe('hello');

    $appSettings->set('greeting', 'world');
    expect($appSettings->get('greeting'))->toBe('world');
});

test('forget removes the row and clears cache', function (): void {
    $appSettings = resolve(AppSettings::class);

    $appSettings->set('temp', 'x');

    expect($appSettings->get('temp'))->toBe('x');

    $appSettings->forget('temp');

    expect($appSettings->get('temp', 'default'))->toBe('default');
    $this->assertDatabaseMissing('app_settings', ['key' => 'temp']);
});

test('values can be arrays', function (): void {
    $appSettings = resolve(AppSettings::class);

    $appSettings->set('nested', ['a' => 1, 'b' => [2, 3]]);

    expect($appSettings->get('nested'))->toBe(['a' => 1, 'b' => [2, 3]]);
});

test('manually inserted rows are returned by get', function (): void {
    AppSetting::create(['key' => 'manual', 'value' => 'direct']);
    Cache::flush();

    expect(resolve(AppSettings::class)->get('manual'))->toBe('direct');
});
