# Phase 1: Foundation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the database schema, models, enums, service connection admin CRUD, webhook endpoint with token middleware, and basic event logging — the foundation everything else builds on.

**Architecture:** Service-Per-Module with encrypted API keys stored in a `service_connections` table. Webhooks arrive at a single endpoint per service/connection, validated by a token middleware, stored as immutable events, and processed async via the queue. The user model gains SSO fields and a role enum.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL 18, Pest 4, Inertia v3, Vue 3, shadcn-vue, Tailwind CSS v4

**Spec:** `docs/superpowers/specs/2026-04-12-mediamanager-design.md`

---

## File Map

### Enums
- Create: `app/Enums/ServiceType.php` — sonarr, radarr, emby, jellyseerr
- Create: `app/Enums/UserRole.php` — admin, member, viewer
- Create: `app/Enums/ActionRequestStatus.php` — pending, approved, executing, completed, failed, rejected

### Migrations
- Create: `database/migrations/xxxx_add_sso_and_role_to_users_table.php`
- Create: `database/migrations/xxxx_create_service_connections_table.php`
- Create: `database/migrations/xxxx_create_webhook_events_table.php`
- Create: `database/migrations/xxxx_create_action_requests_table.php`
- Create: `database/migrations/xxxx_create_action_type_configs_table.php`
- Create: `database/migrations/xxxx_create_emby_user_links_table.php`
- Create: `database/migrations/xxxx_create_emby_activities_table.php`
- Create: `database/migrations/xxxx_create_activity_logs_table.php`

### Models
- Modify: `app/Models/User.php` — add role, sso fields, relationships
- Create: `app/Models/ServiceConnection.php`
- Create: `app/Models/WebhookEvent.php`
- Create: `app/Models/ActionRequest.php`
- Create: `app/Models/ActionTypeConfig.php`
- Create: `app/Models/EmbyUserLink.php`
- Create: `app/Models/EmbyActivity.php`
- Create: `app/Models/ActivityLog.php`

### Factories
- Modify: `database/factories/UserFactory.php` — add role states
- Create: `database/factories/ServiceConnectionFactory.php`
- Create: `database/factories/WebhookEventFactory.php`
- Create: `database/factories/ActionRequestFactory.php`
- Create: `database/factories/ActionTypeConfigFactory.php`
- Create: `database/factories/EmbyUserLinkFactory.php`
- Create: `database/factories/EmbyActivityFactory.php`
- Create: `database/factories/ActivityLogFactory.php`

### Middleware
- Create: `app/Http/Middleware/VerifyWebhookToken.php`
- Create: `app/Http/Middleware/EnsureUserHasRole.php`

### Controllers
- Create: `app/Http/Controllers/Admin/ServiceConnectionController.php`
- Create: `app/Http/Controllers/WebhookController.php`

### Form Requests
- Create: `app/Http/Requests/Admin/ServiceConnectionStoreRequest.php`
- Create: `app/Http/Requests/Admin/ServiceConnectionUpdateRequest.php`

### Routes
- Create: `routes/admin.php`
- Create: `routes/api.php`

### Frontend
- Create: `resources/js/pages/Admin/Connections/Index.vue`
- Create: `resources/js/pages/Admin/Connections/Create.vue`
- Create: `resources/js/pages/Admin/Connections/Edit.vue`

### Tests
- Create: `tests/Feature/Admin/ServiceConnectionTest.php`
- Create: `tests/Feature/Webhooks/WebhookAuthenticationTest.php`
- Create: `tests/Feature/Webhooks/WebhookEventLoggingTest.php`
- Modify: `tests/Pest.php` — enable RefreshDatabase

---

## Task 1: Enable RefreshDatabase and Create Enums

**Files:**
- Modify: `tests/Pest.php`
- Create: `app/Enums/ServiceType.php`
- Create: `app/Enums/UserRole.php`
- Create: `app/Enums/ActionRequestStatus.php`

- [ ] **Step 1: Enable RefreshDatabase in Pest**

In `tests/Pest.php`, uncomment the RefreshDatabase trait:

```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
```

- [ ] **Step 2: Create ServiceType enum**

Create `app/Enums/ServiceType.php`:

```php
<?php

namespace App\Enums;

enum ServiceType: string
{
    case Sonarr = 'sonarr';
    case Radarr = 'radarr';
    case Emby = 'emby';
    case Jellyseerr = 'jellyseerr';

    public function label(): string
    {
        return match ($this) {
            self::Sonarr => 'Sonarr',
            self::Radarr => 'Radarr',
            self::Emby => 'Emby',
            self::Jellyseerr => 'Jellyseerr',
        };
    }
}
```

- [ ] **Step 3: Create UserRole enum**

Create `app/Enums/UserRole.php`:

```php
<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Member => 'Member',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * Check if this role has at least the given role's permissions.
     */
    public function isAtLeast(self $role): bool
    {
        $hierarchy = [self::Admin->value => 3, self::Member->value => 2, self::Viewer->value => 1];

        return $hierarchy[$this->value] >= $hierarchy[$role->value];
    }
}
```

- [ ] **Step 4: Create ActionRequestStatus enum**

Create `app/Enums/ActionRequestStatus.php`:

```php
<?php

namespace App\Enums;

enum ActionRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Executing = 'executing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Executing => 'Executing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Rejected => 'Rejected',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Rejected]);
    }
}
```

- [ ] **Step 5: Commit**

```bash
git add tests/Pest.php app/Enums/
git commit -m "feat: add foundation enums and enable RefreshDatabase

Create ServiceType, UserRole, and ActionRequestStatus enums.
Enable RefreshDatabase trait for feature tests."
```

---

## Task 2: Database Migrations

**Files:**
- Create: 8 migration files via `sail artisan make:migration`

- [ ] **Step 1: Create users table alteration migration**

```bash
vendor/bin/sail artisan make:migration add_sso_and_role_to_users_table --no-interaction
```

Edit the generated migration:

```php
<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sso_provider')->nullable()->after('email');
            $table->string('sso_id')->nullable()->after('sso_provider');
            $table->string('role')->default(UserRole::Viewer->value)->after('sso_id');
            $table->string('avatar_url')->nullable()->after('role');
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['sso_provider', 'sso_id', 'role', 'avatar_url']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 2: Create service_connections migration**

```bash
vendor/bin/sail artisan make:migration create_service_connections_table --no-interaction
```

Edit the generated migration:

```php
<?php

use App\Enums\ServiceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_connections', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('name');
            $table->string('url');
            $table->text('api_key');
            $table->text('webhook_token');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('version')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_connections');
    }
};
```

- [ ] **Step 3: Create webhook_events migration**

```bash
vendor/bin/sail artisan make:migration create_webhook_events_table --no-interaction
```

Edit the generated migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_connection_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
```

- [ ] **Step 4: Create action_type_configs migration**

```bash
vendor/bin/sail artisan make:migration create_action_type_configs_table --no-interaction
```

Edit the generated migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_type_configs', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('requires_approval')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_type_configs');
    }
};
```

- [ ] **Step 5: Create action_requests migration**

```bash
vendor/bin/sail artisan make:migration create_action_requests_table --no-interaction
```

Edit the generated migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('source_service');
            $table->string('target_service');
            $table->string('status')->default('pending');
            $table->boolean('requires_approval')->default(true);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload');
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_requests');
    }
};
```

- [ ] **Step 6: Create emby_user_links migration**

```bash
vendor/bin/sail artisan make:migration create_emby_user_links_table --no-interaction
```

Edit the generated migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emby_user_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emby_user_id');
            $table->string('emby_username');
            $table->timestamps();

            $table->unique(['user_id', 'emby_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emby_user_links');
    }
};
```

- [ ] **Step 7: Create emby_activities migration**

```bash
vendor/bin/sail artisan make:migration create_emby_activities_table --no-interaction
```

Edit the generated migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emby_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emby_user_link_id')->constrained()->cascadeOnDelete();
            $table->string('media_type');
            $table->string('media_title');
            $table->string('series_title')->nullable();
            $table->string('emby_item_id');
            $table->string('action');
            $table->bigInteger('duration_ticks')->nullable();
            $table->bigInteger('play_position')->nullable();
            $table->timestamps();

            $table->index(['emby_user_link_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emby_activities');
    }
};
```

- [ ] **Step 8: Create activity_logs migration**

```bash
vendor/bin/sail artisan make:migration create_activity_logs_table --no-interaction
```

Edit the generated migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
```

- [ ] **Step 9: Run migrations**

```bash
vendor/bin/sail artisan migrate
```

Expected: All migrations run successfully, 0 errors.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/
git commit -m "feat: add all Phase 1 database migrations

Add tables: service_connections, webhook_events, action_requests,
action_type_configs, emby_user_links, emby_activities, activity_logs.
Alter users table with sso_provider, sso_id, role, avatar_url."
```

---

## Task 3: Models and Factories

**Files:**
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Create: `app/Models/ServiceConnection.php`
- Create: `app/Models/WebhookEvent.php`
- Create: `app/Models/ActionRequest.php`
- Create: `app/Models/ActionTypeConfig.php`
- Create: `app/Models/EmbyUserLink.php`
- Create: `app/Models/EmbyActivity.php`
- Create: `app/Models/ActivityLog.php`
- Create: 7 factory files

- [ ] **Step 1: Update User model**

Replace `app/Models/User.php`:

```php
<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'sso_provider', 'sso_id', 'role', 'avatar_url'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isMember(): bool
    {
        return $this->role->isAtLeast(UserRole::Member);
    }

    public function embyUserLinks(): HasMany
    {
        return $this->hasMany(EmbyUserLink::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}
```

- [ ] **Step 2: Update UserFactory**

Replace `database/factories/UserFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Viewer,
            'sso_provider' => null,
            'sso_id' => null,
            'avatar_url' => null,
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
        ]);
    }

    public function member(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Member,
        ]);
    }
}
```

- [ ] **Step 3: Create ServiceConnection model**

```bash
vendor/bin/sail artisan make:model ServiceConnection --no-interaction
```

Replace `app/Models/ServiceConnection.php`:

```php
<?php

namespace App\Models;

use App\Enums\ServiceType;
use Database\Factories\ServiceConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'name', 'url', 'api_key', 'webhook_token', 'is_active', 'version', 'settings'])]
class ServiceConnection extends Model
{
    /** @use HasFactory<ServiceConnectionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ServiceType::class,
            'api_key' => 'encrypted',
            'webhook_token' => 'encrypted',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}
```

- [ ] **Step 4: Create ServiceConnectionFactory**

Create `database/factories/ServiceConnectionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServiceConnection>
 */
class ServiceConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(ServiceType::cases()),
            'name' => fake()->words(2, true),
            'url' => fake()->url(),
            'api_key' => Str::random(32),
            'webhook_token' => Str::random(40),
            'is_active' => true,
            'last_seen_at' => null,
            'version' => null,
            'settings' => null,
        ];
    }

    public function sonarr(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ServiceType::Sonarr,
            'name' => 'Sonarr',
        ]);
    }

    public function radarr(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ServiceType::Radarr,
            'name' => 'Radarr',
        ]);
    }

    public function emby(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ServiceType::Emby,
            'name' => 'Emby',
        ]);
    }

    public function jellyseerr(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ServiceType::Jellyseerr,
            'name' => 'Jellyseerr',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
```

- [ ] **Step 5: Create WebhookEvent model**

```bash
vendor/bin/sail artisan make:model WebhookEvent --no-interaction
```

Replace `app/Models/WebhookEvent.php`:

```php
<?php

namespace App\Models;

use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['service_connection_id', 'event_type', 'payload', 'processed_at'])]
class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function serviceConnection(): BelongsTo
    {
        return $this->belongsTo(ServiceConnection::class);
    }

    public function actionRequests(): HasMany
    {
        return $this->hasMany(ActionRequest::class);
    }

    public function markProcessed(): void
    {
        $this->update(['processed_at' => now()]);
    }
}
```

- [ ] **Step 6: Create WebhookEventFactory**

Create `database/factories/WebhookEventFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_connection_id' => ServiceConnection::factory(),
            'event_type' => fake()->randomElement(['grab', 'download', 'rename', 'test']),
            'payload' => ['eventType' => 'Test', 'data' => fake()->words(3)],
            'processed_at' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'processed_at' => now(),
        ]);
    }
}
```

- [ ] **Step 7: Create ActionRequest model**

```bash
vendor/bin/sail artisan make:model ActionRequest --no-interaction
```

Replace `app/Models/ActionRequest.php`:

```php
<?php

namespace App\Models;

use App\Enums\ActionRequestStatus;
use Database\Factories\ActionRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['webhook_event_id', 'type', 'source_service', 'target_service', 'status', 'requires_approval', 'approved_by', 'payload', 'result'])]
class ActionRequest extends Model
{
    /** @use HasFactory<ActionRequestFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ActionRequestStatus::class,
            'requires_approval' => 'boolean',
            'payload' => 'array',
            'result' => 'array',
        ];
    }

    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPending(): bool
    {
        return $this->status === ActionRequestStatus::Pending;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
```

- [ ] **Step 8: Create ActionRequestFactory**

Create `database/factories/ActionRequestFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionRequest>
 */
class ActionRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_event_id' => WebhookEvent::factory(),
            'type' => 'delete_series',
            'source_service' => 'emby',
            'target_service' => 'sonarr',
            'status' => ActionRequestStatus::Pending,
            'requires_approval' => true,
            'approved_by' => null,
            'payload' => ['series_id' => fake()->randomNumber()],
            'result' => null,
        ];
    }

    public function autoExecute(): static
    {
        return $this->state(fn (array $attributes) => [
            'requires_approval' => false,
            'status' => ActionRequestStatus::Approved,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ActionRequestStatus::Completed,
            'result' => ['success' => true],
        ]);
    }
}
```

- [ ] **Step 9: Create ActionTypeConfig model**

```bash
vendor/bin/sail artisan make:model ActionTypeConfig --no-interaction
```

Replace `app/Models/ActionTypeConfig.php`:

```php
<?php

namespace App\Models;

use Database\Factories\ActionTypeConfigFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'label', 'description', 'requires_approval', 'is_enabled'])]
class ActionTypeConfig extends Model
{
    /** @use HasFactory<ActionTypeConfigFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }
}
```

- [ ] **Step 10: Create ActionTypeConfigFactory**

Create `database/factories/ActionTypeConfigFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ActionTypeConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionTypeConfig>
 */
class ActionTypeConfigFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->unique()->slug(2),
            'label' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'requires_approval' => true,
            'is_enabled' => true,
        ];
    }
}
```

- [ ] **Step 11: Create EmbyUserLink model**

```bash
vendor/bin/sail artisan make:model EmbyUserLink --no-interaction
```

Replace `app/Models/EmbyUserLink.php`:

```php
<?php

namespace App\Models;

use Database\Factories\EmbyUserLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'emby_user_id', 'emby_username'])]
class EmbyUserLink extends Model
{
    /** @use HasFactory<EmbyUserLinkFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(EmbyActivity::class);
    }
}
```

- [ ] **Step 12: Create EmbyUserLinkFactory**

Create `database/factories/EmbyUserLinkFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\EmbyUserLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmbyUserLink>
 */
class EmbyUserLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'emby_user_id' => fake()->uuid(),
            'emby_username' => fake()->userName(),
        ];
    }
}
```

- [ ] **Step 13: Create EmbyActivity model**

```bash
vendor/bin/sail artisan make:model EmbyActivity --no-interaction
```

Replace `app/Models/EmbyActivity.php`:

```php
<?php

namespace App\Models;

use Database\Factories\EmbyActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['emby_user_link_id', 'media_type', 'media_title', 'series_title', 'emby_item_id', 'action', 'duration_ticks', 'play_position'])]
class EmbyActivity extends Model
{
    /** @use HasFactory<EmbyActivityFactory> */
    use HasFactory;

    public function embyUserLink(): BelongsTo
    {
        return $this->belongsTo(EmbyUserLink::class);
    }
}
```

- [ ] **Step 14: Create EmbyActivityFactory**

Create `database/factories/EmbyActivityFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmbyActivity>
 */
class EmbyActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emby_user_link_id' => EmbyUserLink::factory(),
            'media_type' => fake()->randomElement(['movie', 'episode']),
            'media_title' => fake()->words(3, true),
            'series_title' => fake()->optional()->words(2, true),
            'emby_item_id' => fake()->uuid(),
            'action' => fake()->randomElement(['played', 'stopped', 'finished']),
            'duration_ticks' => fake()->optional()->numberBetween(10000000, 90000000000),
            'play_position' => fake()->optional()->numberBetween(0, 90000000000),
        ];
    }
}
```

- [ ] **Step 15: Create ActivityLog model**

```bash
vendor/bin/sail artisan make:model ActivityLog --no-interaction
```

Replace `app/Models/ActivityLog.php`:

```php
<?php

namespace App\Models;

use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['user_id', 'service_connection_id', 'action', 'subject_type', 'subject_id', 'description', 'metadata'])]
class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function serviceConnection(): BelongsTo
    {
        return $this->belongsTo(ServiceConnection::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
```

- [ ] **Step 16: Create ActivityLogFactory**

Create `database/factories/ActivityLogFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'service_connection_id' => null,
            'action' => fake()->randomElement(['created', 'updated', 'deleted']),
            'subject_type' => null,
            'subject_id' => null,
            'description' => fake()->sentence(),
            'metadata' => null,
        ];
    }
}
```

- [ ] **Step 17: Run existing tests to verify nothing is broken**

```bash
vendor/bin/sail artisan test --compact
```

Expected: All existing tests pass.

- [ ] **Step 18: Commit**

```bash
git add app/Models/ database/factories/
git commit -m "feat: add all Phase 1 models and factories

Create models: ServiceConnection, WebhookEvent, ActionRequest,
ActionTypeConfig, EmbyUserLink, EmbyActivity, ActivityLog.
Update User model with role, SSO fields, and relationships.
Add factories with useful state methods for all models."
```

---

## Task 4: EnsureUserHasRole Middleware

**Files:**
- Create: `app/Http/Middleware/EnsureUserHasRole.php`
- Create: `tests/Feature/Admin/RoleMiddlewareTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/RoleMiddlewareTest.php`:

```php
<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['auth', 'role:admin'])->get('/test-admin', fn () => 'ok');
    Route::middleware(['auth', 'role:member'])->get('/test-member', fn () => 'ok');
});

test('admin can access admin routes', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get('/test-admin')
        ->assertOk();
});

test('member cannot access admin routes', function () {
    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get('/test-admin')
        ->assertForbidden();
});

test('viewer cannot access member routes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/test-member')
        ->assertForbidden();
});

test('member can access member routes', function () {
    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get('/test-member')
        ->assertOk();
});

test('admin can access member routes', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get('/test-member')
        ->assertOk();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact --filter=RoleMiddleware
```

Expected: FAIL — middleware alias `role` not registered.

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/EnsureUserHasRole.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $requiredRole = UserRole::from($role);

        if (! $request->user()?->role->isAtLeast($requiredRole)) {
            abort(403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the middleware alias in bootstrap/app.php**

Add the middleware alias in `bootstrap/app.php` inside the `withMiddleware` callback:

```php
$middleware->alias([
    'role' => \App\Http\Middleware\EnsureUserHasRole::class,
]);
```

Add this line after the `$middleware->web(append: [...])` block.

- [ ] **Step 5: Run tests to verify they pass**

```bash
vendor/bin/sail artisan test --compact --filter=RoleMiddleware
```

Expected: All 5 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/EnsureUserHasRole.php tests/Feature/Admin/RoleMiddlewareTest.php bootstrap/app.php
git commit -m "feat: add EnsureUserHasRole middleware

Role-based route protection using UserRole hierarchy.
Admins can access member routes, but not vice versa."
```

---

## Task 5: Webhook Token Middleware

**Files:**
- Create: `app/Http/Middleware/VerifyWebhookToken.php`
- Create: `tests/Feature/Webhooks/WebhookAuthenticationTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Webhooks/WebhookAuthenticationTest.php`:

```php
<?php

use App\Models\ServiceConnection;

test('webhook with valid token is accepted', function () {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'webhook_token' => 'test-secret-token',
    ]);

    $this->postJson(
        "/api/webhooks/sonarr/{$connection->id}",
        ['eventType' => 'Test'],
        ['X-Webhook-Token' => 'test-secret-token']
    )->assertOk();
});

test('webhook with invalid token is rejected', function () {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'webhook_token' => 'test-secret-token',
    ]);

    $this->postJson(
        "/api/webhooks/sonarr/{$connection->id}",
        ['eventType' => 'Test'],
        ['X-Webhook-Token' => 'wrong-token']
    )->assertUnauthorized();
});

test('webhook with missing token is rejected', function () {
    $connection = ServiceConnection::factory()->sonarr()->create();

    $this->postJson(
        "/api/webhooks/sonarr/{$connection->id}",
        ['eventType' => 'Test']
    )->assertUnauthorized();
});

test('webhook for inactive connection is rejected', function () {
    $connection = ServiceConnection::factory()->sonarr()->inactive()->create([
        'webhook_token' => 'test-secret-token',
    ]);

    $this->postJson(
        "/api/webhooks/sonarr/{$connection->id}",
        ['eventType' => 'Test'],
        ['X-Webhook-Token' => 'test-secret-token']
    )->assertNotFound();
});

test('webhook for non-existent connection returns 404', function () {
    $this->postJson(
        '/api/webhooks/sonarr/999',
        ['eventType' => 'Test'],
        ['X-Webhook-Token' => 'some-token']
    )->assertNotFound();
});

test('webhook for mismatched service type returns 404', function () {
    $connection = ServiceConnection::factory()->radarr()->create([
        'webhook_token' => 'test-secret-token',
    ]);

    $this->postJson(
        "/api/webhooks/sonarr/{$connection->id}",
        ['eventType' => 'Test'],
        ['X-Webhook-Token' => 'test-secret-token']
    )->assertNotFound();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact --filter=WebhookAuthentication
```

Expected: FAIL — route not defined.

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/VerifyWebhookToken.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $serviceType = $request->route('service');
        $connectionId = $request->route('connection');

        $connection = ServiceConnection::query()
            ->where('id', $connectionId)
            ->where('type', $serviceType)
            ->where('is_active', true)
            ->first();

        if (! $connection) {
            abort(404);
        }

        $token = $request->header('X-Webhook-Token');

        if (! $token || $token !== $connection->webhook_token) {
            abort(401);
        }

        $request->attributes->set('service_connection', $connection);

        return $next($request);
    }
}
```

- [ ] **Step 4: Create the WebhookController**

Create `app/Http/Controllers/WebhookController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        /** @var ServiceConnection $connection */
        $connection = $request->attributes->get('service_connection');

        WebhookEvent::create([
            'service_connection_id' => $connection->id,
            'event_type' => $request->input('eventType', 'unknown'),
            'payload' => $request->all(),
        ]);

        return response()->json(['status' => 'received']);
    }
}
```

- [ ] **Step 5: Create the API routes file**

Create `routes/api.php`:

```php
<?php

use App\Http\Controllers\WebhookController;
use App\Http\Middleware\VerifyWebhookToken;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/{service}/{connection}', [WebhookController::class, 'handle'])
    ->middleware(VerifyWebhookToken::class)
    ->name('webhooks.handle');
```

- [ ] **Step 6: Register the API routes in bootstrap/app.php**

Update `bootstrap/app.php` to add the api route file. In the `->withRouting()` call, add the `api` parameter:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
vendor/bin/sail artisan test --compact --filter=WebhookAuthentication
```

Expected: All 6 tests PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Middleware/VerifyWebhookToken.php app/Http/Controllers/WebhookController.php routes/api.php bootstrap/app.php tests/Feature/Webhooks/WebhookAuthenticationTest.php
git commit -m "feat: add webhook endpoint with token authentication

POST /api/webhooks/{service}/{connection} validates X-Webhook-Token
header against the stored encrypted token per connection.
Rejects inactive connections and mismatched service types."
```

---

## Task 6: Webhook Event Logging Tests

**Files:**
- Create: `tests/Feature/Webhooks/WebhookEventLoggingTest.php`

- [ ] **Step 1: Write the tests**

Create `tests/Feature/Webhooks/WebhookEventLoggingTest.php`:

```php
<?php

use App\Models\ServiceConnection;
use App\Models\WebhookEvent;

test('webhook stores event in database', function () {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'webhook_token' => 'test-token',
    ]);

    $payload = [
        'eventType' => 'Download',
        'series' => ['title' => 'Breaking Bad'],
        'episodes' => [['episodeNumber' => 1]],
    ];

    $this->postJson(
        "/api/webhooks/sonarr/{$connection->id}",
        $payload,
        ['X-Webhook-Token' => 'test-token']
    )->assertOk();

    $this->assertDatabaseHas('webhook_events', [
        'service_connection_id' => $connection->id,
        'event_type' => 'Download',
    ]);

    $event = WebhookEvent::first();
    expect($event->payload)->toMatchArray($payload);
    expect($event->processed_at)->toBeNull();
});

test('webhook stores event with unknown type when eventType missing', function () {
    $connection = ServiceConnection::factory()->emby()->create([
        'webhook_token' => 'test-token',
    ]);

    $this->postJson(
        "/api/webhooks/emby/{$connection->id}",
        ['some' => 'data'],
        ['X-Webhook-Token' => 'test-token']
    )->assertOk();

    $this->assertDatabaseHas('webhook_events', [
        'service_connection_id' => $connection->id,
        'event_type' => 'unknown',
    ]);
});

test('multiple webhooks create separate events', function () {
    $connection = ServiceConnection::factory()->radarr()->create([
        'webhook_token' => 'test-token',
    ]);

    $headers = ['X-Webhook-Token' => 'test-token'];

    $this->postJson("/api/webhooks/radarr/{$connection->id}", ['eventType' => 'Grab'], $headers);
    $this->postJson("/api/webhooks/radarr/{$connection->id}", ['eventType' => 'Download'], $headers);

    expect(WebhookEvent::count())->toBe(2);
    expect(WebhookEvent::pluck('event_type')->toArray())->toBe(['Grab', 'Download']);
});
```

- [ ] **Step 2: Run tests to verify they pass**

```bash
vendor/bin/sail artisan test --compact --filter=WebhookEventLogging
```

Expected: All 3 tests PASS (the controller and routes already exist from Task 5).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Webhooks/WebhookEventLoggingTest.php
git commit -m "test: add webhook event logging tests

Verify webhooks are stored in database with correct payload,
event type defaults to 'unknown' when missing, and multiple
webhooks create separate event records."
```

---

## Task 7: Service Connection Admin CRUD — Backend

**Files:**
- Create: `app/Http/Controllers/Admin/ServiceConnectionController.php`
- Create: `app/Http/Requests/Admin/ServiceConnectionStoreRequest.php`
- Create: `app/Http/Requests/Admin/ServiceConnectionUpdateRequest.php`
- Create: `routes/admin.php`
- Create: `tests/Feature/Admin/ServiceConnectionTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Admin/ServiceConnectionTest.php`:

```php
<?php

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Models\User;

test('guests cannot access service connections', function () {
    $this->get(route('admin.connections.index'))
        ->assertRedirect(route('login'));
});

test('non-admin users cannot access service connections', function () {
    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get(route('admin.connections.index'))
        ->assertForbidden();
});

test('admin can list service connections', function () {
    $admin = User::factory()->admin()->create();
    ServiceConnection::factory()->sonarr()->create();
    ServiceConnection::factory()->radarr()->create();

    $this->actingAs($admin)
        ->get(route('admin.connections.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Connections/Index')
            ->has('connections', 2)
        );
});

test('admin can view create form', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.connections.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Connections/Create')
            ->has('serviceTypes')
        );
});

test('admin can store a service connection', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.connections.store'), [
            'type' => 'sonarr',
            'name' => 'My Sonarr',
            'url' => 'http://sonarr.local:8989',
            'api_key' => 'abc123def456',
            'webhook_token' => 'my-webhook-secret',
        ])
        ->assertRedirect(route('admin.connections.index'));

    $this->assertDatabaseHas('service_connections', [
        'type' => 'sonarr',
        'name' => 'My Sonarr',
        'url' => 'http://sonarr.local:8989',
    ]);

    $connection = ServiceConnection::first();
    expect($connection->api_key)->toBe('abc123def456');
    expect($connection->webhook_token)->toBe('my-webhook-secret');
});

test('store validates required fields', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.connections.store'), [])
        ->assertSessionHasErrors(['type', 'name', 'url', 'api_key', 'webhook_token']);
});

test('store validates service type', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.connections.store'), [
            'type' => 'invalid',
            'name' => 'Test',
            'url' => 'http://example.com',
            'api_key' => 'key',
            'webhook_token' => 'token',
        ])
        ->assertSessionHasErrors('type');
});

test('store validates url format', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.connections.store'), [
            'type' => 'sonarr',
            'name' => 'Test',
            'url' => 'not-a-url',
            'api_key' => 'key',
            'webhook_token' => 'token',
        ])
        ->assertSessionHasErrors('url');
});

test('admin can view edit form', function () {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();

    $this->actingAs($admin)
        ->get(route('admin.connections.edit', $connection))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Connections/Edit')
            ->has('connection')
            ->has('serviceTypes')
        );
});

test('admin can update a service connection', function () {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $connection), [
            'type' => 'sonarr',
            'name' => 'Updated Sonarr',
            'url' => 'http://new-sonarr.local:8989',
            'api_key' => 'new-key',
            'webhook_token' => 'new-token',
        ])
        ->assertRedirect(route('admin.connections.index'));

    $connection->refresh();
    expect($connection->name)->toBe('Updated Sonarr');
    expect($connection->url)->toBe('http://new-sonarr.local:8989');
});

test('admin can delete a service connection', function () {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();

    $this->actingAs($admin)
        ->delete(route('admin.connections.destroy', $connection))
        ->assertRedirect(route('admin.connections.index'));

    $this->assertDatabaseMissing('service_connections', ['id' => $connection->id]);
});

test('admin can toggle connection active status', function () {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create(['is_active' => true]);

    $this->actingAs($admin)
        ->patch(route('admin.connections.toggle', $connection))
        ->assertRedirect(route('admin.connections.index'));

    $connection->refresh();
    expect($connection->is_active)->toBeFalse();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact --filter=ServiceConnectionTest
```

Expected: FAIL — routes not defined.

- [ ] **Step 3: Create the Store form request**

Create `app/Http/Requests/Admin/ServiceConnectionStoreRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Enums\ServiceType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceConnectionStoreRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(ServiceType::class)],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:500'],
            'api_key' => ['required', 'string', 'max:500'],
            'webhook_token' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
```

- [ ] **Step 4: Create the Update form request**

Create `app/Http/Requests/Admin/ServiceConnectionUpdateRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Enums\ServiceType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceConnectionUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(ServiceType::class)],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:500'],
            'api_key' => ['required', 'string', 'max:500'],
            'webhook_token' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
```

- [ ] **Step 5: Create the controller**

Create `app/Http/Controllers/Admin/ServiceConnectionController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceConnectionStoreRequest;
use App\Http\Requests\Admin\ServiceConnectionUpdateRequest;
use App\Models\ServiceConnection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServiceConnectionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Connections/Index', [
            'connections' => ServiceConnection::query()
                ->orderBy('name')
                ->get()
                ->map(fn (ServiceConnection $connection) => [
                    'id' => $connection->id,
                    'type' => $connection->type,
                    'name' => $connection->name,
                    'url' => $connection->url,
                    'is_active' => $connection->is_active,
                    'last_seen_at' => $connection->last_seen_at?->diffForHumans(),
                    'version' => $connection->version,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Connections/Create', [
            'serviceTypes' => collect(ServiceType::cases())->map(fn (ServiceType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
        ]);
    }

    public function store(ServiceConnectionStoreRequest $request): RedirectResponse
    {
        ServiceConnection::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection created.')]);

        return to_route('admin.connections.index');
    }

    public function edit(ServiceConnection $connection): Response
    {
        return Inertia::render('Admin/Connections/Edit', [
            'connection' => [
                'id' => $connection->id,
                'type' => $connection->type,
                'name' => $connection->name,
                'url' => $connection->url,
                'api_key' => $connection->api_key,
                'webhook_token' => $connection->webhook_token,
                'is_active' => $connection->is_active,
            ],
            'serviceTypes' => collect(ServiceType::cases())->map(fn (ServiceType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
        ]);
    }

    public function update(ServiceConnectionUpdateRequest $request, ServiceConnection $connection): RedirectResponse
    {
        $connection->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection updated.')]);

        return to_route('admin.connections.index');
    }

    public function destroy(ServiceConnection $connection): RedirectResponse
    {
        $connection->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection deleted.')]);

        return to_route('admin.connections.index');
    }

    public function toggle(ServiceConnection $connection): RedirectResponse
    {
        $connection->update(['is_active' => ! $connection->is_active]);

        $status = $connection->is_active ? 'enabled' : 'disabled';
        Inertia::flash('toast', ['type' => 'success', 'message' => __("Connection {$status}.")]);

        return to_route('admin.connections.index');
    }
}
```

- [ ] **Step 6: Create the admin routes file**

Create `routes/admin.php`:

```php
<?php

use App\Http\Controllers\Admin\ServiceConnectionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('connections', ServiceConnectionController::class)->except(['show']);
    Route::patch('connections/{connection}/toggle', [ServiceConnectionController::class, 'toggle'])
        ->name('connections.toggle');
});
```

- [ ] **Step 7: Register admin routes in web.php**

Add to the bottom of `routes/web.php`:

```php
require __DIR__.'/admin.php';
```

- [ ] **Step 8: Run tests to verify they pass**

```bash
vendor/bin/sail artisan test --compact --filter=ServiceConnectionTest
```

Expected: All 12 tests PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Admin/ app/Http/Requests/Admin/ routes/admin.php routes/web.php tests/Feature/Admin/ServiceConnectionTest.php
git commit -m "feat: add service connection admin CRUD

Admin-only CRUD for managing Sonarr/Radarr/Emby/Jellyseerr connections.
Encrypted API keys and webhook tokens. Toggle active/inactive status.
Full validation with ServiceType enum constraint."
```

---

## Task 8: Service Connection Admin — Frontend Pages

**Files:**
- Create: `resources/js/pages/Admin/Connections/Index.vue`
- Create: `resources/js/pages/Admin/Connections/Create.vue`
- Create: `resources/js/pages/Admin/Connections/Edit.vue`

- [ ] **Step 1: Check existing frontend patterns**

Read these files for conventions before writing pages:
- `resources/js/pages/settings/Profile.vue` — form patterns
- `resources/js/pages/Dashboard.vue` — page structure
- `resources/js/layouts/AppLayout.vue` — layout usage
- `resources/js/components/ui/` — available shadcn components

- [ ] **Step 2: Create the Index page**

Create `resources/js/pages/Admin/Connections/Index.vue`:

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table'
import { Link, router } from '@inertiajs/vue3'
import { type BreadcrumbItem } from '@/types'

interface Connection {
    id: number
    type: { value: string; label?: string }
    name: string
    url: string
    is_active: boolean
    last_seen_at: string | null
    version: string | null
}

const props = defineProps<{
    connections: Connection[]
}>()

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '#' },
    { title: 'Connections', href: route('admin.connections.index') },
]

function typeLabel(type: { value: string; label?: string } | string): string {
    if (typeof type === 'string') {
        return type.charAt(0).toUpperCase() + type.slice(1)
    }
    return type.label ?? type.value
}

function toggleConnection(connection: Connection) {
    router.patch(route('admin.connections.toggle', connection.id))
}

function deleteConnection(connection: Connection) {
    if (confirm(`Delete ${connection.name}? This cannot be undone.`)) {
        router.delete(route('admin.connections.destroy', connection.id))
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight">Service Connections</h2>
                    <p class="text-muted-foreground">Manage your external service integrations.</p>
                </div>
                <Link :href="route('admin.connections.create')">
                    <Button>Add Connection</Button>
                </Link>
            </div>

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>URL</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Last Seen</TableHead>
                        <TableHead>Version</TableHead>
                        <TableHead class="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="connection in connections" :key="connection.id">
                        <TableCell class="font-medium">{{ connection.name }}</TableCell>
                        <TableCell>
                            <Badge variant="outline">{{ typeLabel(connection.type) }}</Badge>
                        </TableCell>
                        <TableCell class="text-muted-foreground">{{ connection.url }}</TableCell>
                        <TableCell>
                            <Badge :variant="connection.is_active ? 'default' : 'secondary'">
                                {{ connection.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ connection.last_seen_at ?? 'Never' }}
                        </TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ connection.version ?? '-' }}
                        </TableCell>
                        <TableCell class="text-right space-x-2">
                            <Link :href="route('admin.connections.edit', connection.id)">
                                <Button variant="ghost" size="sm">Edit</Button>
                            </Link>
                            <Button variant="ghost" size="sm" @click="toggleConnection(connection)">
                                {{ connection.is_active ? 'Disable' : 'Enable' }}
                            </Button>
                            <Button variant="ghost" size="sm" class="text-destructive" @click="deleteConnection(connection)">
                                Delete
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="connections.length === 0">
                        <TableCell :colspan="7" class="text-center text-muted-foreground py-8">
                            No connections configured yet. Add one to get started.
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 3: Create the Create page**

Create `resources/js/pages/Admin/Connections/Create.vue`:

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useForm, Link } from '@inertiajs/vue3'
import { type BreadcrumbItem } from '@/types'

interface ServiceTypeOption {
    value: string
    label: string
}

const props = defineProps<{
    serviceTypes: ServiceTypeOption[]
}>()

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '#' },
    { title: 'Connections', href: route('admin.connections.index') },
    { title: 'Add Connection', href: route('admin.connections.create') },
]

const form = useForm({
    type: '',
    name: '',
    url: '',
    api_key: '',
    webhook_token: '',
})

function submit() {
    form.post(route('admin.connections.store'))
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="max-w-2xl p-6">
            <Card>
                <CardHeader>
                    <CardTitle>Add Service Connection</CardTitle>
                    <CardDescription>
                        Connect an external service to MediaManager.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submit" class="space-y-4">
                        <div class="space-y-2">
                            <Label for="type">Service Type</Label>
                            <Select v-model="form.type">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a service type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="serviceType in serviceTypes"
                                        :key="serviceType.value"
                                        :value="serviceType.value"
                                    >
                                        {{ serviceType.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p v-if="form.errors.type" class="text-sm text-destructive">{{ form.errors.type }}</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="name">Display Name</Label>
                            <Input id="name" v-model="form.name" placeholder="My Sonarr" />
                            <p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="url">URL</Label>
                            <Input id="url" v-model="form.url" placeholder="http://sonarr.local:8989" />
                            <p v-if="form.errors.url" class="text-sm text-destructive">{{ form.errors.url }}</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="api_key">API Key</Label>
                            <Input id="api_key" v-model="form.api_key" type="password" placeholder="Enter API key" />
                            <p v-if="form.errors.api_key" class="text-sm text-destructive">{{ form.errors.api_key }}</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="webhook_token">Webhook Token</Label>
                            <Input id="webhook_token" v-model="form.webhook_token" type="password" placeholder="Token for webhook authentication" />
                            <p class="text-sm text-muted-foreground">
                                Configure this token in the service's webhook settings as the X-Webhook-Token header.
                            </p>
                            <p v-if="form.errors.webhook_token" class="text-sm text-destructive">{{ form.errors.webhook_token }}</p>
                        </div>

                        <div class="flex gap-2 pt-4">
                            <Button type="submit" :disabled="form.processing">Create Connection</Button>
                            <Link :href="route('admin.connections.index')">
                                <Button type="button" variant="outline">Cancel</Button>
                            </Link>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 4: Create the Edit page**

Create `resources/js/pages/Admin/Connections/Edit.vue`:

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useForm, Link } from '@inertiajs/vue3'
import { type BreadcrumbItem } from '@/types'

interface ServiceTypeOption {
    value: string
    label: string
}

interface Connection {
    id: number
    type: { value: string } | string
    name: string
    url: string
    api_key: string
    webhook_token: string
    is_active: boolean
}

const props = defineProps<{
    connection: Connection
    serviceTypes: ServiceTypeOption[]
}>()

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '#' },
    { title: 'Connections', href: route('admin.connections.index') },
    { title: 'Edit', href: route('admin.connections.edit', props.connection.id) },
]

const typeValue = typeof props.connection.type === 'string'
    ? props.connection.type
    : props.connection.type.value

const form = useForm({
    type: typeValue,
    name: props.connection.name,
    url: props.connection.url,
    api_key: props.connection.api_key,
    webhook_token: props.connection.webhook_token,
})

function submit() {
    form.put(route('admin.connections.update', props.connection.id))
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="max-w-2xl p-6">
            <Card>
                <CardHeader>
                    <CardTitle>Edit Connection</CardTitle>
                    <CardDescription>
                        Update the settings for this service connection.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submit" class="space-y-4">
                        <div class="space-y-2">
                            <Label for="type">Service Type</Label>
                            <Select v-model="form.type">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a service type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="serviceType in serviceTypes"
                                        :key="serviceType.value"
                                        :value="serviceType.value"
                                    >
                                        {{ serviceType.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p v-if="form.errors.type" class="text-sm text-destructive">{{ form.errors.type }}</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="name">Display Name</Label>
                            <Input id="name" v-model="form.name" />
                            <p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="url">URL</Label>
                            <Input id="url" v-model="form.url" />
                            <p v-if="form.errors.url" class="text-sm text-destructive">{{ form.errors.url }}</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="api_key">API Key</Label>
                            <Input id="api_key" v-model="form.api_key" type="password" />
                            <p v-if="form.errors.api_key" class="text-sm text-destructive">{{ form.errors.api_key }}</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="webhook_token">Webhook Token</Label>
                            <Input id="webhook_token" v-model="form.webhook_token" type="password" />
                            <p class="text-sm text-muted-foreground">
                                Configure this token in the service's webhook settings as the X-Webhook-Token header.
                            </p>
                            <p v-if="form.errors.webhook_token" class="text-sm text-destructive">{{ form.errors.webhook_token }}</p>
                        </div>

                        <div class="flex gap-2 pt-4">
                            <Button type="submit" :disabled="form.processing">Update Connection</Button>
                            <Link :href="route('admin.connections.index')">
                                <Button type="button" variant="outline">Cancel</Button>
                            </Link>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 5: Run all tests**

```bash
vendor/bin/sail artisan test --compact
```

Expected: All tests PASS.

- [ ] **Step 6: Format code**

```bash
vendor/bin/sail bin rector
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 7: Generate Wayfinder routes**

```bash
vendor/bin/sail artisan wayfinder:generate
```

- [ ] **Step 8: Build frontend**

```bash
vendor/bin/sail npm run build
```

- [ ] **Step 9: Commit**

```bash
git add resources/js/pages/Admin/
git commit -m "feat: add service connection admin frontend pages

Index page with table listing all connections.
Create/Edit forms with validation for all fields.
Toggle active/inactive and delete with confirmation."
```

---

## Task 9: Final Verification

- [ ] **Step 1: Run full test suite**

```bash
vendor/bin/sail artisan test --compact
```

Expected: All tests pass (existing auth tests + new webhook + admin tests).

- [ ] **Step 2: Verify migrations are clean**

```bash
vendor/bin/sail artisan migrate:fresh
```

Expected: All migrations run without errors.

- [ ] **Step 3: Run formatter**

```bash
vendor/bin/sail bin rector
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 4: Run tests one final time**

```bash
vendor/bin/sail artisan test --compact
```

Expected: All pass.

- [ ] **Step 5: Final commit if any formatting changes**

```bash
git add -A
git status
# Only commit if there are changes from formatting
git commit -m "chore: apply pint and rector formatting"
```
