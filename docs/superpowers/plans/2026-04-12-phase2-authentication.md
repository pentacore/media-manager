# Phase 2: Authentication — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Authentik OIDC login, Emby credential login, first-user-is-admin logic, updated login page with three auth methods, and admin user management.

**Architecture:** Authentik uses Laravel Socialite with the community `socialiteproviders/authentik` provider for standard OIDC flow. Emby uses a custom controller that validates credentials against the Emby API, then creates a Laravel session. A shared `FindOrCreateSsoUser` action handles user creation/linking across all SSO providers with first-user-is-admin logic. All three auth methods ultimately call `Auth::login()` and use Fortify's session management.

**Tech Stack:** Laravel 13, Socialite, socialiteproviders/authentik, Pest 4, Vue 3, Inertia v3, shadcn-vue

**Spec:** `docs/superpowers/specs/2026-04-12-mediamanager-design.md` (Authentication section)

---

## File Map

### Actions
- Create: `app/Actions/FindOrCreateSsoUser.php` — shared SSO user creation/linking with first-user-admin logic
- Modify: `app/Actions/Fortify/CreateNewUser.php` — add first-user-admin for local registration

### Controllers
- Create: `app/Http/Controllers/Auth/AuthentikController.php` — OIDC redirect + callback
- Create: `app/Http/Controllers/Auth/EmbyAuthController.php` — Emby credential auth
- Create: `app/Http/Controllers/Admin/UserController.php` — user management CRUD

### Form Requests
- Create: `app/Http/Requests/Auth/EmbyLoginRequest.php` — validates Emby login form
- Create: `app/Http/Requests/Admin/UpdateUserRoleRequest.php` — validates role change

### Config
- Modify: `config/services.php` — add Authentik OIDC credentials
- Modify: `.env.example` — add Authentik env vars
- Modify: `app/Providers/AppServiceProvider.php` — register Authentik Socialite provider

### Routes
- Modify: `routes/web.php` — add Authentik and Emby auth routes
- Modify: `routes/admin.php` — add user management routes

### Frontend
- Modify: `resources/js/pages/auth/Login.vue` — three auth methods UI
- Create: `resources/js/pages/Admin/Users/Index.vue` — user management page
- Modify: `app/Providers/FortifyServiceProvider.php` — add auth feature flags to login view

### Tests
- Create: `tests/Feature/Auth/AuthentikAuthTest.php`
- Create: `tests/Feature/Auth/EmbyAuthTest.php`
- Create: `tests/Feature/Auth/FirstUserAdminTest.php`
- Create: `tests/Feature/Admin/UserManagementTest.php`

---

## Task 1: Install Packages

**Files:**
- Modify: `composer.json` (via composer)

- [ ] **Step 1: Install Socialite and Authentik provider**

```bash
vendor/bin/sail composer require laravel/socialite socialiteproviders/authentik
```

- [ ] **Step 2: Verify installation**

```bash
vendor/bin/sail artisan test --compact
```

Expected: All 66 existing tests still pass.

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: install laravel/socialite and socialiteproviders/authentik"
```

---

## Task 2: Configure Authentik + Register Provider

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example`
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Add Authentik config to services.php**

Add after the `'slack'` entry in `config/services.php`:

```php
'authentik' => [
    'client_id' => env('AUTHENTIK_CLIENT_ID'),
    'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
    'redirect' => env('AUTHENTIK_REDIRECT_URI'),
    'base_url' => env('AUTHENTIK_BASE_URL'),
],
```

- [ ] **Step 2: Add env vars to .env.example**

Add at the bottom of `.env.example`:

```
AUTHENTIK_CLIENT_ID=
AUTHENTIK_CLIENT_SECRET=
AUTHENTIK_BASE_URL=
AUTHENTIK_REDIRECT_URI="${APP_URL}/auth/authentik/callback"
```

- [ ] **Step 3: Register Authentik Socialite provider**

In `app/Providers/AppServiceProvider.php`, add to the `boot()` method:

```php
use Illuminate\Support\Facades\Event;
use SocialiteProviders\Authentik\AuthentikExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

// Inside boot():
Event::listen(SocialiteWasCalled::class, AuthentikExtendSocialite::class);
```

- [ ] **Step 4: Commit**

```bash
git add config/services.php .env.example app/Providers/AppServiceProvider.php
git commit -m "feat: configure Authentik OIDC provider

Add Authentik service config with env vars.
Register Socialite community provider via SocialiteWasCalled event."
```

---

## Task 3: FindOrCreateSsoUser Action + First-User-Admin

**Files:**
- Create: `app/Actions/FindOrCreateSsoUser.php`
- Modify: `app/Actions/Fortify/CreateNewUser.php`
- Create: `tests/Feature/Auth/FirstUserAdminTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Auth/FirstUserAdminTest.php`:

```php
<?php

use App\Actions\FindOrCreateSsoUser;
use App\Enums\UserRole;
use App\Models\User;

test('first user created via SSO gets admin role', function (): void {
    $action = new FindOrCreateSsoUser();

    $user = $action->execute(
        provider: 'authentik',
        ssoId: 'auth-123',
        email: 'first@example.com',
        name: 'First User',
    );

    expect($user->role)->toBe(UserRole::Admin);
});

test('second user created via SSO gets viewer role', function (): void {
    User::factory()->admin()->create();

    $action = new FindOrCreateSsoUser();

    $user = $action->execute(
        provider: 'authentik',
        ssoId: 'auth-456',
        email: 'second@example.com',
        name: 'Second User',
    );

    expect($user->role)->toBe(UserRole::Viewer);
});

test('SSO login links existing user by email', function (): void {
    $existing = User::factory()->create(['email' => 'match@example.com']);

    $action = new FindOrCreateSsoUser();

    $user = $action->execute(
        provider: 'authentik',
        ssoId: 'auth-789',
        email: 'match@example.com',
        name: 'Match User',
    );

    expect($user->id)->toBe($existing->id);
    expect($user->sso_provider)->toBe('authentik');
    expect($user->sso_id)->toBe('auth-789');
});

test('SSO login finds returning user by sso_id', function (): void {
    $existing = User::factory()->create([
        'sso_provider' => 'authentik',
        'sso_id' => 'auth-returning',
    ]);

    $action = new FindOrCreateSsoUser();

    $user = $action->execute(
        provider: 'authentik',
        ssoId: 'auth-returning',
        email: 'different@example.com',
        name: 'Different Name',
    );

    expect($user->id)->toBe($existing->id);
    expect(User::count())->toBe(1);
});

test('first user registered via Fortify gets admin role', function (): void {
    $response = $this->post(route('register'), [
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $user = User::first();
    expect($user->role)->toBe(UserRole::Admin);
});

test('second user registered via Fortify gets viewer role', function (): void {
    User::factory()->admin()->create();

    $this->post(route('register'), [
        'name' => 'Viewer User',
        'email' => 'viewer@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $user = User::where('email', 'viewer@example.com')->first();
    expect($user->role)->toBe(UserRole::Viewer);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact --filter=FirstUserAdmin
```

Expected: FAIL — `FindOrCreateSsoUser` class not found.

- [ ] **Step 3: Create FindOrCreateSsoUser action**

Create `app/Actions/FindOrCreateSsoUser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\User;

class FindOrCreateSsoUser
{
    public function execute(
        string $provider,
        string $ssoId,
        string $email,
        string $name,
        ?string $avatarUrl = null,
    ): User {
        // Find returning SSO user by provider + sso_id
        $user = User::where('sso_provider', $provider)
            ->where('sso_id', $ssoId)
            ->first();

        if ($user) {
            return $user;
        }

        // Find existing user by email and link SSO
        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'sso_provider' => $provider,
                'sso_id' => $ssoId,
                'avatar_url' => $avatarUrl ?? $user->avatar_url,
            ]);

            return $user;
        }

        // Create new user — admin if first user, viewer otherwise
        $role = User::count() === 0 ? UserRole::Admin : UserRole::Viewer;

        return User::create([
            'name' => $name,
            'email' => $email,
            'sso_provider' => $provider,
            'sso_id' => $ssoId,
            'avatar_url' => $avatarUrl,
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
```

- [ ] **Step 4: Update CreateNewUser for first-user-admin**

Replace `app/Actions/Fortify/CreateNewUser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $role = User::count() === 0 ? UserRole::Admin : UserRole::Viewer;

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'role' => $role,
        ]);
    }
}
```

- [ ] **Step 5: Run tests**

```bash
vendor/bin/sail artisan test --compact --filter=FirstUserAdmin
```

Expected: All 6 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/FindOrCreateSsoUser.php app/Actions/Fortify/CreateNewUser.php tests/Feature/Auth/FirstUserAdminTest.php
git commit -m "feat: add FindOrCreateSsoUser action with first-user-admin logic

Shared action for SSO user creation/linking. First user gets admin role,
subsequent users get viewer. Links existing users by email on first SSO login.
Updated CreateNewUser for first-user-admin on local registration."
```

---

## Task 4: Authentik Auth Controller + Tests

**Files:**
- Create: `app/Http/Controllers/Auth/AuthentikController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Auth/AuthentikAuthTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Auth/AuthentikAuthTest.php`:

```php
<?php

use App\Enums\UserRole;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function mockSocialiteUser(string $id = 'auth-123', string $email = 'sso@example.com', string $name = 'SSO User', ?string $avatar = null): void
{
    $socialiteUser = new SocialiteUser();
    $socialiteUser->id = $id;
    $socialiteUser->email = $email;
    $socialiteUser->name = $name;
    $socialiteUser->avatar = $avatar;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);
}

test('authentik redirect sends to provider', function (): void {
    Socialite::shouldReceive('driver->redirect')->once()->andReturn(redirect('https://authentik.example.com'));

    $this->get(route('auth.authentik'))
        ->assertRedirect();
});

test('authentik callback creates new user', function (): void {
    mockSocialiteUser();

    $this->get(route('auth.authentik.callback'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    $user = User::where('email', 'sso@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->sso_provider)->toBe('authentik');
    expect($user->sso_id)->toBe('auth-123');
    expect($user->name)->toBe('SSO User');
    expect($user->role)->toBe(UserRole::Admin); // first user
});

test('authentik callback assigns viewer to non-first user', function (): void {
    User::factory()->admin()->create();

    mockSocialiteUser(id: 'auth-456', email: 'second@example.com', name: 'Second');

    $this->get(route('auth.authentik.callback'));

    $user = User::where('email', 'second@example.com')->first();
    expect($user->role)->toBe(UserRole::Viewer);
});

test('authentik callback links existing user by email', function (): void {
    $existing = User::factory()->create(['email' => 'existing@example.com']);

    mockSocialiteUser(id: 'auth-link', email: 'existing@example.com', name: 'Linked');

    $this->get(route('auth.authentik.callback'));

    $this->assertAuthenticated();

    $existing->refresh();
    expect($existing->sso_provider)->toBe('authentik');
    expect($existing->sso_id)->toBe('auth-link');
});

test('authentik callback logs in returning SSO user', function (): void {
    User::factory()->create([
        'sso_provider' => 'authentik',
        'sso_id' => 'auth-returning',
        'email' => 'returning@example.com',
    ]);

    mockSocialiteUser(id: 'auth-returning', email: 'returning@example.com');

    $this->get(route('auth.authentik.callback'));

    $this->assertAuthenticated();
    expect(User::count())->toBe(1);
});

test('authentik callback handles provider error gracefully', function (): void {
    Socialite::shouldReceive('driver->user')->andThrow(new \Exception('Provider error'));

    $this->get(route('auth.authentik.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact --filter=AuthentikAuth
```

Expected: FAIL — route not defined.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Auth/AuthentikController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\FindOrCreateSsoUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class AuthentikController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('authentik')->redirect();
    }

    public function callback(FindOrCreateSsoUser $action): RedirectResponse
    {
        try {
            $socialiteUser = Socialite::driver('authentik')->user();
        } catch (\Throwable) {
            return redirect()->route('login')->with('status', __('Authentication failed. Please try again.'));
        }

        $user = $action->execute(
            provider: 'authentik',
            ssoId: (string) $socialiteUser->getId(),
            email: $socialiteUser->getEmail(),
            name: $socialiteUser->getName(),
            avatarUrl: $socialiteUser->getAvatar(),
        );

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }
}
```

- [ ] **Step 4: Add routes**

Add to `routes/web.php`, before the `require __DIR__.'/admin.php'` line:

```php
use App\Http\Controllers\Auth\AuthentikController;
use App\Http\Controllers\Auth\EmbyAuthController;

Route::middleware('guest')->group(function () {
    Route::get('auth/authentik', [AuthentikController::class, 'redirect'])->name('auth.authentik');
    Route::get('auth/authentik/callback', [AuthentikController::class, 'callback'])->name('auth.authentik.callback');
});
```

- [ ] **Step 5: Run tests**

```bash
vendor/bin/sail artisan test --compact --filter=AuthentikAuth
```

Expected: All 6 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Auth/AuthentikController.php routes/web.php tests/Feature/Auth/AuthentikAuthTest.php
git commit -m "feat: add Authentik OIDC authentication

Socialite-based OIDC flow with redirect and callback.
Creates new users, links existing users by email, or logs in returning SSO users.
First user gets admin role via FindOrCreateSsoUser action."
```

---

## Task 5: Emby Auth Controller + Tests

**Files:**
- Create: `app/Http/Controllers/Auth/EmbyAuthController.php`
- Create: `app/Http/Requests/Auth/EmbyLoginRequest.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Auth/EmbyAuthTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Auth/EmbyAuthTest.php`:

```php
<?php

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->embyConnection = ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'emby-api-key',
    ]);
});

test('emby login succeeds with valid credentials', function (): void {
    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response([
            'User' => [
                'Id' => 'emby-user-123',
                'Name' => 'EmbyUser',
            ],
            'AccessToken' => 'some-token',
        ]),
    ]);

    $this->post(route('auth.emby'), [
        'username' => 'EmbyUser',
        'password' => 'embypass',
        'email' => 'emby@example.com',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    $user = User::where('email', 'emby@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('EmbyUser');
    expect($user->role)->toBe(UserRole::Admin); // first user

    expect(EmbyUserLink::where('user_id', $user->id)->where('emby_user_id', 'emby-user-123')->exists())->toBeTrue();
});

test('emby login with existing link skips email requirement', function (): void {
    $user = User::factory()->create();
    EmbyUserLink::factory()->create([
        'user_id' => $user->id,
        'emby_user_id' => 'emby-user-456',
        'emby_username' => 'ReturningUser',
    ]);

    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response([
            'User' => [
                'Id' => 'emby-user-456',
                'Name' => 'ReturningUser',
            ],
            'AccessToken' => 'some-token',
        ]),
    ]);

    $this->post(route('auth.emby'), [
        'username' => 'ReturningUser',
        'password' => 'pass',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();
    expect(User::count())->toBe(1);
});

test('emby login fails with invalid credentials', function (): void {
    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response([], 401),
    ]);

    $this->post(route('auth.emby'), [
        'username' => 'BadUser',
        'password' => 'wrongpass',
        'email' => 'bad@example.com',
    ])->assertRedirect(route('login'))
      ->assertSessionHasErrors('username');
});

test('emby login fails when no active emby connection exists', function (): void {
    $this->embyConnection->update(['is_active' => false]);

    $this->post(route('auth.emby'), [
        'username' => 'User',
        'password' => 'pass',
        'email' => 'user@example.com',
    ])->assertRedirect(route('login'))
      ->assertSessionHasErrors('username');
});

test('emby first login requires email for new users', function (): void {
    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response([
            'User' => ['Id' => 'emby-new', 'Name' => 'NewUser'],
            'AccessToken' => 'token',
        ]),
    ]);

    $this->post(route('auth.emby'), [
        'username' => 'NewUser',
        'password' => 'pass',
        // no email
    ])->assertSessionHasErrors('email');
});

test('emby second user gets viewer role', function (): void {
    User::factory()->admin()->create();

    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response([
            'User' => ['Id' => 'emby-second', 'Name' => 'Second'],
            'AccessToken' => 'token',
        ]),
    ]);

    $this->post(route('auth.emby'), [
        'username' => 'Second',
        'password' => 'pass',
        'email' => 'second@example.com',
    ]);

    $user = User::where('email', 'second@example.com')->first();
    expect($user->role)->toBe(UserRole::Viewer);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact --filter=EmbyAuth
```

Expected: FAIL — route not defined.

- [ ] **Step 3: Create the form request**

Create `app/Http/Requests/Auth/EmbyLoginRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EmbyLoginRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/Auth/EmbyAuthController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EmbyLoginRequest;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class EmbyAuthController extends Controller
{
    public function store(EmbyLoginRequest $request): RedirectResponse
    {
        $connection = ServiceConnection::where('type', ServiceType::Emby)
            ->where('is_active', true)
            ->first();

        if (! $connection) {
            return back()->withErrors(['username' => __('Emby authentication is not available.')]);
        }

        $response = Http::withHeaders([
            'X-Emby-Token' => $connection->api_key,
        ])->post("{$connection->url}/Users/AuthenticateByName", [
            'Username' => $request->input('username'),
            'Pw' => $request->input('password'),
        ]);

        if (! $response->successful()) {
            return back()->withErrors(['username' => __('Invalid Emby credentials.')]);
        }

        $embyUserId = $response->json('User.Id');
        $embyUsername = $response->json('User.Name');

        // Find existing linked user
        $link = EmbyUserLink::where('emby_user_id', $embyUserId)->first();

        if ($link) {
            Auth::login($link->user, remember: true);

            return redirect()->intended(route('dashboard'));
        }

        // New Emby user — require email
        if (! $request->filled('email')) {
            return back()->withErrors(['email' => __('Email is required for first-time Emby login.')]);
        }

        $email = $request->input('email');

        // Find existing user by email or create new one
        $user = User::where('email', $email)->first();

        if (! $user) {
            $role = User::count() === 0 ? UserRole::Admin : UserRole::Viewer;

            $user = User::create([
                'name' => $embyUsername,
                'email' => $email,
                'role' => $role,
                'email_verified_at' => now(),
            ]);
        }

        EmbyUserLink::create([
            'user_id' => $user->id,
            'emby_user_id' => $embyUserId,
            'emby_username' => $embyUsername,
        ]);

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }
}
```

- [ ] **Step 5: Add Emby route to web.php**

In the `Route::middleware('guest')->group(...)` block already added in Task 4, add:

```php
Route::post('auth/emby', [EmbyAuthController::class, 'store'])->name('auth.emby');
```

And add the import at the top of web.php:

```php
use App\Http\Controllers\Auth\EmbyAuthController;
```

- [ ] **Step 6: Run tests**

```bash
vendor/bin/sail artisan test --compact --filter=EmbyAuth
```

Expected: All 6 tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Auth/EmbyAuthController.php app/Http/Requests/Auth/EmbyLoginRequest.php routes/web.php tests/Feature/Auth/EmbyAuthTest.php
git commit -m "feat: add Emby credential authentication

Authenticates against Emby API, creates EmbyUserLink on first login.
Requires email for new users. Returns returning users via linked account.
First user gets admin role."
```

---

## Task 6: Update Login Page + Feature Flags

**Files:**
- Modify: `app/Providers/FortifyServiceProvider.php`
- Modify: `resources/js/pages/auth/Login.vue`

- [ ] **Step 1: Add feature flags to FortifyServiceProvider**

In `app/Providers/FortifyServiceProvider.php`, update the `loginView` closure in `configureViews()`. Change:

```php
Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
    'canResetPassword' => Features::enabled(Features::resetPasswords()),
    'canRegister' => Features::enabled(Features::registration()),
    'status' => $request->session()->get('status'),
]));
```

To:

```php
Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
    'canResetPassword' => Features::enabled(Features::resetPasswords()),
    'canRegister' => Features::enabled(Features::registration()),
    'status' => $request->session()->get('status'),
    'authentikEnabled' => filled(config('services.authentik.client_id')),
    'embyEnabled' => \App\Models\ServiceConnection::where('type', \App\Enums\ServiceType::Emby)->where('is_active', true)->exists(),
]));
```

- [ ] **Step 2: Replace Login.vue**

Replace `resources/js/pages/auth/Login.vue`:

```vue
<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3'
import InputError from '@/components/InputError.vue'
import PasswordInput from '@/components/PasswordInput.vue'
import TextLink from '@/components/TextLink.vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Separator } from '@/components/ui/separator'
import { Spinner } from '@/components/ui/spinner'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible'
import { register } from '@/routes'
import { store } from '@/routes/login'
import { request } from '@/routes/password'
import { ref } from 'vue'

defineOptions({
    layout: {
        title: 'Log in to your account',
        description: 'Choose your preferred sign-in method',
    },
})

defineProps<{
    status?: string
    canResetPassword: boolean
    canRegister: boolean
    authentikEnabled: boolean
    embyEnabled: boolean
}>()

const showEmbyForm = ref(false)
const showLocalForm = ref(false)
</script>

<template>
    <Head title="Log in" />

    <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600">
        {{ status }}
    </div>

    <div class="flex flex-col gap-4">
        <!-- Authentik SSO -->
        <a v-if="authentikEnabled" :href="route('auth.authentik')" class="w-full">
            <Button variant="default" class="w-full" size="lg">
                Sign in with Authentik
            </Button>
        </a>

        <!-- Emby Login -->
        <div v-if="embyEnabled">
            <Button
                v-if="!showEmbyForm"
                variant="outline"
                class="w-full"
                size="lg"
                @click="showEmbyForm = true"
            >
                Sign in with Emby
            </Button>

            <div v-if="showEmbyForm" class="space-y-4 rounded-lg border p-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-medium">Sign in with Emby</h3>
                    <Button variant="ghost" size="sm" @click="showEmbyForm = false">Cancel</Button>
                </div>

                <Form
                    method="post"
                    :url="route('auth.emby')"
                    v-slot="{ errors, processing }"
                    class="space-y-4"
                >
                    <div class="grid gap-2">
                        <Label for="emby-username">Emby Username</Label>
                        <Input id="emby-username" name="username" required placeholder="Username" />
                        <InputError :message="errors.username" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="emby-password">Emby Password</Label>
                        <PasswordInput id="emby-password" name="password" required placeholder="Password" />
                        <InputError :message="errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="emby-email">Email <span class="text-muted-foreground">(required for first login)</span></Label>
                        <Input id="emby-email" name="email" type="email" placeholder="email@example.com" />
                        <InputError :message="errors.email" />
                    </div>

                    <Button type="submit" class="w-full" :disabled="processing">
                        <Spinner v-if="processing" />
                        Sign in with Emby
                    </Button>
                </Form>
            </div>
        </div>

        <!-- Divider -->
        <div v-if="authentikEnabled || embyEnabled" class="relative my-2">
            <Separator />
            <span class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 bg-background px-2 text-xs text-muted-foreground">
                or sign in with email
            </span>
        </div>

        <!-- Local Login (collapsible if other methods available) -->
        <template v-if="authentikEnabled || embyEnabled">
            <Collapsible v-model:open="showLocalForm">
                <CollapsibleTrigger as-child>
                    <Button variant="ghost" class="w-full text-muted-foreground" size="sm">
                        {{ showLocalForm ? 'Hide email login' : 'Sign in with email' }}
                    </Button>
                </CollapsibleTrigger>
                <CollapsibleContent class="mt-4">
                    <Form
                        v-bind="store.form()"
                        :reset-on-success="['password']"
                        v-slot="{ errors, processing }"
                        class="flex flex-col gap-6"
                    >
                        <div class="grid gap-6">
                            <div class="grid gap-2">
                                <Label for="email">Email address</Label>
                                <Input id="email" type="email" name="email" required autofocus autocomplete="email" placeholder="email@example.com" />
                                <InputError :message="errors.email" />
                            </div>
                            <div class="grid gap-2">
                                <div class="flex items-center justify-between">
                                    <Label for="password">Password</Label>
                                    <TextLink v-if="canResetPassword" :href="request()" class="text-sm">Forgot password?</TextLink>
                                </div>
                                <PasswordInput id="password" name="password" required autocomplete="current-password" placeholder="Password" />
                                <InputError :message="errors.password" />
                            </div>
                            <div class="flex items-center justify-between">
                                <Label for="remember" class="flex items-center space-x-3">
                                    <Checkbox id="remember" name="remember" />
                                    <span>Remember me</span>
                                </Label>
                            </div>
                            <Button type="submit" class="w-full" :disabled="processing" data-test="login-button">
                                <Spinner v-if="processing" />
                                Log in
                            </Button>
                        </div>
                    </Form>
                </CollapsibleContent>
            </Collapsible>
        </template>

        <!-- Local Login (full form when no other methods) -->
        <template v-else>
            <Form
                v-bind="store.form()"
                :reset-on-success="['password']"
                v-slot="{ errors, processing }"
                class="flex flex-col gap-6"
            >
                <div class="grid gap-6">
                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input id="email" type="email" name="email" required autofocus autocomplete="email" placeholder="email@example.com" />
                        <InputError :message="errors.email" />
                    </div>
                    <div class="grid gap-2">
                        <div class="flex items-center justify-between">
                            <Label for="password">Password</Label>
                            <TextLink v-if="canResetPassword" :href="request()" class="text-sm">Forgot password?</TextLink>
                        </div>
                        <PasswordInput id="password" name="password" required autocomplete="current-password" placeholder="Password" />
                        <InputError :message="errors.password" />
                    </div>
                    <div class="flex items-center justify-between">
                        <Label for="remember" class="flex items-center space-x-3">
                            <Checkbox id="remember" name="remember" />
                            <span>Remember me</span>
                        </Label>
                    </div>
                    <Button type="submit" class="w-full" :disabled="processing" data-test="login-button">
                        <Spinner v-if="processing" />
                        Log in
                    </Button>
                </div>
            </Form>
        </template>

        <div class="text-center text-sm text-muted-foreground" v-if="canRegister">
            Don't have an account?
            <TextLink :href="register()">Sign up</TextLink>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Generate Wayfinder routes and build frontend**

```bash
vendor/bin/sail artisan wayfinder:generate
vendor/bin/sail npm run build
```

- [ ] **Step 4: Run all tests**

```bash
vendor/bin/sail artisan test --compact
```

Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Providers/FortifyServiceProvider.php resources/js/pages/auth/Login.vue
git commit -m "feat: update login page with three auth methods

Show Authentik (primary), Emby (expandable form), and local email
(collapsible) login options. Feature-flagged based on Authentik
config and active Emby connections."
```

---

## Task 7: User Management Admin — Backend

**Files:**
- Create: `app/Http/Controllers/Admin/UserController.php`
- Create: `app/Http/Requests/Admin/UpdateUserRoleRequest.php`
- Modify: `routes/admin.php`
- Create: `tests/Feature/Admin/UserManagementTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Admin/UserManagementTest.php`:

```php
<?php

use App\Enums\UserRole;
use App\Models\User;

test('guests cannot access user management', function (): void {
    $this->get(route('admin.users.index'))
        ->assertRedirect(route('login'));
});

test('non-admin cannot access user management', function (): void {
    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

test('admin can list users', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->member()->create();
    User::factory()->create(); // viewer

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Index')
            ->has('users', 3)
        );
});

test('admin can change user role', function (): void {
    $admin = User::factory()->admin()->create();
    $viewer = User::factory()->create();

    $this->actingAs($admin)
        ->patch(route('admin.users.update-role', $viewer), [
            'role' => 'member',
        ])
        ->assertRedirect(route('admin.users.index'));

    $viewer->refresh();
    expect($viewer->role)->toBe(UserRole::Member);
});

test('admin cannot change own role', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patch(route('admin.users.update-role', $admin), [
            'role' => 'viewer',
        ])
        ->assertForbidden();
});

test('role validation rejects invalid roles', function (): void {
    $admin = User::factory()->admin()->create();
    $viewer = User::factory()->create();

    $this->actingAs($admin)
        ->patch(route('admin.users.update-role', $viewer), [
            'role' => 'superadmin',
        ])
        ->assertSessionHasErrors('role');
});

test('admin can delete user', function (): void {
    $admin = User::factory()->admin()->create();
    $viewer = User::factory()->create();

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $viewer))
        ->assertRedirect(route('admin.users.index'));

    $this->assertDatabaseMissing('users', ['id' => $viewer->id]);
});

test('admin cannot delete themselves', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $admin))
        ->assertForbidden();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact --filter=UserManagement
```

- [ ] **Step 3: Create the form request**

Create `app/Http/Requests/Admin/UpdateUserRoleRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRoleRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::enum(UserRole::class)],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/Admin/UserController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Users/Index', [
            'users' => User::query()
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'sso_provider' => $user->sso_provider,
                    'avatar_url' => $user->avatar_url,
                    'created_at' => $user->created_at->diffForHumans(),
                ]),
        ]);
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user): RedirectResponse
    {
        abort_if($user->id === $request->user()->id, 403);

        $user->update(['role' => $request->validated('role')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User role updated.')]);

        return to_route('admin.users.index');
    }

    public function destroy(User $user): RedirectResponse
    {
        abort_if($user->id === request()->user()->id, 403);

        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('admin.users.index');
    }
}
```

- [ ] **Step 5: Add routes**

Add to `routes/admin.php`, inside the existing middleware group:

```php
use App\Http\Controllers\Admin\UserController;

Route::get('users', [UserController::class, 'index'])->name('users.index');
Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->name('users.update-role');
Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
```

- [ ] **Step 6: Run tests**

```bash
vendor/bin/sail artisan test --compact --filter=UserManagement
```

Expected: All 8 tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/UserController.php app/Http/Requests/Admin/UpdateUserRoleRequest.php routes/admin.php tests/Feature/Admin/UserManagementTest.php
git commit -m "feat: add user management admin page

Admin can list users, change roles, and delete users.
Cannot modify own role or delete self. Role validated against UserRole enum."
```

---

## Task 8: User Management Admin — Frontend

**Files:**
- Create: `resources/js/pages/Admin/Users/Index.vue`

- [ ] **Step 1: Create the Users index page**

Create `resources/js/pages/Admin/Users/Index.vue`:

```vue
<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3'
import UserController from '@/actions/App/Http/Controllers/Admin/UserController'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select'
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table'
import { computed } from 'vue'

interface UserItem {
    id: number
    name: string
    email: string
    role: { value: string; label?: string } | string
    sso_provider: string | null
    avatar_url: string | null
    created_at: string
}

const props = defineProps<{
    users: UserItem[]
}>()

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: '#' },
            { title: 'Users', href: UserController.index.url() },
        ],
    },
})

const page = usePage()
const currentUserId = computed(() => page.props.auth.user?.id)

function roleValue(role: UserItem['role']): string {
    return typeof role === 'string' ? role : role.value
}

function roleLabel(role: UserItem['role']): string {
    if (typeof role === 'string') {
        return role.charAt(0).toUpperCase() + role.slice(1)
    }
    return role.label ?? role.value
}

function authMethod(ssoProvider: string | null): string {
    if (!ssoProvider) return 'Local'
    return ssoProvider.charAt(0).toUpperCase() + ssoProvider.slice(1)
}

function initials(name: string): string {
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

function updateRole(user: UserItem, newRole: string) {
    router.visit(UserController.updateRole.url(user.id), {
        method: 'patch',
        data: { role: newRole },
    })
}

function deleteUser(user: UserItem) {
    if (confirm(`Delete ${user.name}? This cannot be undone.`)) {
        router.visit(UserController.destroy.url(user.id), {
            method: 'delete',
        })
    }
}
</script>

<template>
    <Head title="User Management" />

    <div class="space-y-6 p-6">
        <div>
            <h2 class="text-2xl font-bold tracking-tight">Users</h2>
            <p class="text-muted-foreground">Manage user accounts and roles.</p>
        </div>

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>User</TableHead>
                    <TableHead>Email</TableHead>
                    <TableHead>Role</TableHead>
                    <TableHead>Auth Method</TableHead>
                    <TableHead>Joined</TableHead>
                    <TableHead class="text-right">Actions</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="user in users" :key="user.id">
                    <TableCell>
                        <div class="flex items-center gap-3">
                            <Avatar class="h-8 w-8">
                                <AvatarImage v-if="user.avatar_url" :src="user.avatar_url" :alt="user.name" />
                                <AvatarFallback>{{ initials(user.name) }}</AvatarFallback>
                            </Avatar>
                            <span class="font-medium">{{ user.name }}</span>
                        </div>
                    </TableCell>
                    <TableCell class="text-muted-foreground">{{ user.email }}</TableCell>
                    <TableCell>
                        <Select
                            v-if="user.id !== currentUserId"
                            :default-value="roleValue(user.role)"
                            @update:model-value="(val: string) => updateRole(user, val)"
                        >
                            <SelectTrigger class="w-28">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="admin">Admin</SelectItem>
                                <SelectItem value="member">Member</SelectItem>
                                <SelectItem value="viewer">Viewer</SelectItem>
                            </SelectContent>
                        </Select>
                        <Badge v-else variant="outline">{{ roleLabel(user.role) }}</Badge>
                    </TableCell>
                    <TableCell>
                        <Badge variant="secondary">{{ authMethod(user.sso_provider) }}</Badge>
                    </TableCell>
                    <TableCell class="text-muted-foreground">{{ user.created_at }}</TableCell>
                    <TableCell class="text-right">
                        <Button
                            v-if="user.id !== currentUserId"
                            variant="ghost"
                            size="sm"
                            class="text-destructive"
                            @click="deleteUser(user)"
                        >
                            Delete
                        </Button>
                        <span v-else class="text-xs text-muted-foreground">You</span>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
```

- [ ] **Step 2: Generate Wayfinder routes and build**

```bash
vendor/bin/sail artisan wayfinder:generate
vendor/bin/sail npm run build
```

- [ ] **Step 3: Run all tests**

```bash
vendor/bin/sail artisan test --compact
```

Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/Admin/Users/
git commit -m "feat: add user management admin frontend

Table with inline role selector, avatar, auth method badge.
Admin cannot change own role or delete self."
```

---

## Task 9: Final Verification

- [ ] **Step 1: Run full test suite**

```bash
vendor/bin/sail artisan test --compact
```

- [ ] **Step 2: Run pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 3: Run rector**

```bash
vendor/bin/sail bin rector
```

IMPORTANT: After rector, check controller files for renamed route model binding parameters (e.g., `$user` renamed to `$userModel`). Revert any such renames — Laravel requires the parameter name to match the route placeholder `{user}`.

- [ ] **Step 4: Run tests again after formatting**

```bash
vendor/bin/sail artisan test --compact
```

- [ ] **Step 5: Build frontend**

```bash
vendor/bin/sail npm run build
```

- [ ] **Step 6: Commit formatting changes if any**

```bash
git add -A && git status
git commit -m "chore: apply pint and rector formatting"
```
