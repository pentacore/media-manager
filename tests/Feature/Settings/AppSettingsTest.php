<?php

declare(strict_types=1);

use App\Models\AppSetting;
use App\Settings\AppSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

test('a value read back inside a rolled-back save is not served from the cache afterwards', function (): void {
    $appSettings = resolve(AppSettings::class);
    $appSettings->set('greeting', 'hello');

    expect(fn () => DB::transaction(function () use ($appSettings): void {
        $appSettings->set('greeting', 'world');
        // A getter inside the save caches the uncommitted value.
        expect($appSettings->get('greeting'))->toBe('world');

        throw new RuntimeException('roll back');
    }))->toThrow(RuntimeException::class, 'roll back');

    expect($appSettings->get('greeting'))->toBe('hello');
});

test('an old value another reader cached while the save was open is evicted when it commits', function (): void {
    $appSettings = resolve(AppSettings::class);
    $appSettings->set('greeting', 'hello');

    DB::transaction(function () use ($appSettings): void {
        $appSettings->set('greeting', 'world');
        // Another worker, still seeing the committed row, caches it.
        Cache::put('app_settings:greeting', 'hello', 60);
    });

    expect($appSettings->get('greeting'))->toBe('world');
});
