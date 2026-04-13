# Phase 3: Service Clients — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build HTTP API client wrappers for Sonarr, Radarr, Emby, and Jellyseerr with a shared base class for *arr services.

**Architecture:** A shared `ArrClient` base class handles common *arr API v3 endpoints (status, quality profiles, root folders, commands, disk space). `SonarrClient` and `RadarrClient` extend it with series/movie CRUD. `EmbyClient` and `JellyseerrClient` are standalone. All clients accept a `ServiceConnection` model, use Laravel's `Http` facade with explicit timeouts and retry policies, and are testable with `Http::fake()`.

**Tech Stack:** Laravel 13 Http client, Pest 4, `Http::fake()` + `Http::preventStrayRequests()`

**Spec:** `docs/superpowers/specs/2026-04-12-mediamanager-design.md` (Module Structure section)

---

## File Map

### Service Clients
- Create: `app/Services/Arr/ArrClient.php` — abstract base for shared *arr API v3
- Create: `app/Services/Sonarr/SonarrClient.php` — series CRUD, extends ArrClient
- Create: `app/Services/Radarr/RadarrClient.php` — movie CRUD, extends ArrClient
- Create: `app/Services/Emby/EmbyClient.php` — Emby server API
- Create: `app/Services/Jellyseerr/JellyseerrClient.php` — Jellyseerr API

### Tests
- Create: `tests/Feature/Services/ArrClientTest.php`
- Create: `tests/Feature/Services/SonarrClientTest.php`
- Create: `tests/Feature/Services/RadarrClientTest.php`
- Create: `tests/Feature/Services/EmbyClientTest.php`
- Create: `tests/Feature/Services/JellyseerrClientTest.php`

---

## Task 1: ArrClient Base Class + Tests

**Files:**
- Create: `app/Services/Arr/ArrClient.php`
- Create: `tests/Feature/Services/ArrClientTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Services/ArrClientTest.php`:

```php
<?php

use App\Models\ServiceConnection;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test-api-key',
    ]);
});

test('sends X-Api-Key header with requests', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/system/status' => Http::response(['appName' => 'Sonarr']),
    ]);

    $client = new SonarrClient($this->connection);
    $client->getSystemStatus();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Api-Key', 'test-api-key'));
});

test('uses correct base URL without trailing slash', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989/',
        'api_key' => 'key',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/system/status' => Http::response(['appName' => 'Sonarr']),
    ]);

    $client = new SonarrClient($connection);
    $client->getSystemStatus();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'sonarr.local:8989/api/v3/system/status'));
});

test('getSystemStatus returns parsed response', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/system/status' => Http::response([
            'appName' => 'Sonarr',
            'version' => '4.0.0.1',
            'buildTime' => '2024-01-01T00:00:00Z',
        ]),
    ]);

    $client = new SonarrClient($this->connection);
    $result = $client->getSystemStatus();

    expect($result)->toMatchArray([
        'appName' => 'Sonarr',
        'version' => '4.0.0.1',
    ]);
});

test('getQualityProfiles returns array of profiles', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([
            ['id' => 1, 'name' => 'HD-1080p'],
            ['id' => 2, 'name' => '4K'],
        ]),
    ]);

    $client = new SonarrClient($this->connection);
    $result = $client->getQualityProfiles();

    expect($result)->toHaveCount(2);
    expect($result[0]['name'])->toBe('HD-1080p');
});

test('getRootFolders returns array of folders', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([
            ['id' => 1, 'path' => '/tv', 'freeSpace' => 500000000000],
        ]),
    ]);

    $client = new SonarrClient($this->connection);
    $result = $client->getRootFolders();

    expect($result)->toHaveCount(1);
    expect($result[0]['path'])->toBe('/tv');
});

test('getDiskSpace returns disk entries', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/diskspace' => Http::response([
            ['path' => '/tv', 'freeSpace' => 500000000000, 'totalSpace' => 1000000000000],
        ]),
    ]);

    $client = new SonarrClient($this->connection);
    $result = $client->getDiskSpace();

    expect($result)->toHaveCount(1);
    expect($result[0]['totalSpace'])->toBe(1000000000000);
});

test('runCommand sends correct POST body', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/command' => Http::response(['id' => 1, 'name' => 'RefreshSeries']),
    ]);

    $client = new SonarrClient($this->connection);
    $result = $client->runCommand('RefreshSeries', ['seriesId' => 42]);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->method() === 'POST'
            && $body['name'] === 'RefreshSeries'
            && $body['seriesId'] === 42;
    });

    expect($result['name'])->toBe('RefreshSeries');
});

test('throws RequestException on server error', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/system/status' => Http::response([], 500),
    ]);

    $client = new SonarrClient($this->connection);
    $client->getSystemStatus();
})->throws(RequestException::class);

test('throws on client error', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/system/status' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $client = new SonarrClient($this->connection);
    $client->getSystemStatus();
})->throws(RequestException::class);
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact --filter=ArrClientTest
```

Expected: FAIL — classes not found.

- [ ] **Step 3: Create the ArrClient base class**

Create `app/Services/Arr/ArrClient.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Arr;

use App\Models\ServiceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

abstract class ArrClient
{
    public function __construct(
        protected ServiceConnection $connection,
    ) {}

    protected function buildClient(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->connection->url, '/'))
            ->withHeaders(['X-Api-Key' => $this->connection->api_key])
            ->timeout(10)
            ->connectTimeout(3)
            ->retry(
                times: 3,
                sleepMilliseconds: [100, 500, 1000],
                when: fn (\Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()),
                throw: false,
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function getSystemStatus(): array
    {
        return $this->buildClient()->get('/api/v3/system/status')->throw()->json();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getQualityProfiles(): array
    {
        return $this->buildClient()->get('/api/v3/qualityprofile')->throw()->json();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRootFolders(): array
    {
        return $this->buildClient()->get('/api/v3/rootfolder')->throw()->json();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDiskSpace(): array
    {
        return $this->buildClient()->get('/api/v3/diskspace')->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function runCommand(string $name, array $params = []): array
    {
        return $this->buildClient()->post('/api/v3/command', [
            'name' => $name,
            ...$params,
        ])->throw()->json();
    }
}
```

Also create a minimal `SonarrClient` stub so the tests can instantiate it (full implementation in Task 2):

Create `app/Services/Sonarr/SonarrClient.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Sonarr;

use App\Services\Arr\ArrClient;

class SonarrClient extends ArrClient {}
```

- [ ] **Step 4: Run tests**

```bash
vendor/bin/sail artisan test --compact --filter=ArrClientTest
```

Expected: All 9 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ tests/Feature/Services/
git commit -m "feat: add ArrClient base class for shared *arr API v3

Abstract base with system status, quality profiles, root folders,
disk space, and command execution. Explicit timeouts, retry with
backoff on 5xx/connection errors. Tested via SonarrClient stub."
```

---

## Task 2: SonarrClient + Tests

**Files:**
- Modify: `app/Services/Sonarr/SonarrClient.php`
- Create: `tests/Feature/Services/SonarrClientTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Services/SonarrClientTest.php`:

```php
<?php

use App\Models\ServiceConnection;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test-api-key',
    ]);

    $this->client = new SonarrClient($this->connection);
});

test('getSeries returns array of series', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([
            ['id' => 1, 'title' => 'Breaking Bad', 'year' => 2008],
            ['id' => 2, 'title' => 'The Bear', 'year' => 2022],
        ]),
    ]);

    $result = $this->client->getSeries();

    expect($result)->toHaveCount(2);
    expect($result[0]['title'])->toBe('Breaking Bad');
});

test('getSeriesById returns single series', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42,
            'title' => 'Breaking Bad',
            'seasonCount' => 5,
        ]),
    ]);

    $result = $this->client->getSeriesById(42);

    expect($result['id'])->toBe(42);
    expect($result['title'])->toBe('Breaking Bad');
});

test('addSeries sends POST with data', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([
            'id' => 99,
            'title' => 'New Show',
        ]),
    ]);

    $data = ['title' => 'New Show', 'tvdbId' => 12345, 'qualityProfileId' => 1, 'rootFolderPath' => '/tv'];
    $result = $this->client->addSeries($data);

    Http::assertSent(function (Request $request) use ($data): bool {
        return $request->method() === 'POST'
            && $request->data()['title'] === 'New Show'
            && $request->data()['tvdbId'] === 12345;
    });

    expect($result['id'])->toBe(99);
});

test('updateSeries sends PUT with data', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42,
            'title' => 'Breaking Bad',
            'monitored' => false,
        ]),
    ]);

    $result = $this->client->updateSeries(42, ['monitored' => false]);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'PUT'
            && $request->data()['monitored'] === false;
    });

    expect($result['monitored'])->toBeFalse();
});

test('deleteSeries sends DELETE without deleteFiles by default', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/*' => Http::response([], 200),
    ]);

    $this->client->deleteSeries(42);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/v3/series/42')
            && str_contains($request->url(), 'deleteFiles=false');
    });
});

test('deleteSeries with deleteFiles sends correct query param', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/*' => Http::response([], 200),
    ]);

    $this->client->deleteSeries(42, deleteFiles: true);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'deleteFiles=true');
    });
});

test('searchSeries encodes query and returns results', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([
            ['title' => 'Breaking Bad', 'tvdbId' => 81189],
        ]),
    ]);

    $result = $this->client->searchSeries('breaking bad');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'term=breaking');
    });

    expect($result)->toHaveCount(1);
    expect($result[0]['title'])->toBe('Breaking Bad');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact --filter=SonarrClientTest
```

Expected: FAIL — methods not found.

- [ ] **Step 3: Implement SonarrClient**

Replace `app/Services/Sonarr/SonarrClient.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Sonarr;

use App\Services\Arr\ArrClient;

class SonarrClient extends ArrClient
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSeries(): array
    {
        return $this->buildClient()->get('/api/v3/series')->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getSeriesById(int $id): array
    {
        return $this->buildClient()->get("/api/v3/series/{$id}")->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function addSeries(array $data): array
    {
        return $this->buildClient()->post('/api/v3/series', $data)->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function updateSeries(int $id, array $data): array
    {
        return $this->buildClient()->put("/api/v3/series/{$id}", $data)->throw()->json();
    }

    public function deleteSeries(int $id, bool $deleteFiles = false): void
    {
        $this->buildClient()
            ->delete("/api/v3/series/{$id}", ['deleteFiles' => $deleteFiles ? 'true' : 'false'])
            ->throw();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchSeries(string $query): array
    {
        return $this->buildClient()->get('/api/v3/series/lookup', ['term' => $query])->throw()->json();
    }
}
```

- [ ] **Step 4: Run tests**

```bash
vendor/bin/sail artisan test --compact --filter=SonarrClientTest
```

Expected: All 7 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Sonarr/SonarrClient.php tests/Feature/Services/SonarrClientTest.php
git commit -m "feat: add SonarrClient with series CRUD

Get, add, update, delete series. Search by term.
DeleteFiles flag on deletion. Extends ArrClient base."
```

---

## Task 3: RadarrClient + Tests

**Files:**
- Create: `app/Services/Radarr/RadarrClient.php`
- Create: `tests/Feature/Services/RadarrClientTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Services/RadarrClientTest.php`:

```php
<?php

use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'test-api-key',
    ]);

    $this->client = new RadarrClient($this->connection);
});

test('getMovies returns array of movies', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie' => Http::response([
            ['id' => 1, 'title' => 'Inception', 'year' => 2010],
            ['id' => 2, 'title' => 'Dune', 'year' => 2021],
        ]),
    ]);

    $result = $this->client->getMovies();

    expect($result)->toHaveCount(2);
    expect($result[0]['title'])->toBe('Inception');
});

test('getMovieById returns single movie', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/42' => Http::response([
            'id' => 42,
            'title' => 'Inception',
            'year' => 2010,
        ]),
    ]);

    $result = $this->client->getMovieById(42);

    expect($result['id'])->toBe(42);
    expect($result['title'])->toBe('Inception');
});

test('addMovie sends POST with data', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie' => Http::response([
            'id' => 99,
            'title' => 'New Movie',
        ]),
    ]);

    $data = ['title' => 'New Movie', 'tmdbId' => 12345, 'qualityProfileId' => 1, 'rootFolderPath' => '/movies'];
    $result = $this->client->addMovie($data);

    Http::assertSent(function (Request $request) use ($data): bool {
        return $request->method() === 'POST'
            && $request->data()['title'] === 'New Movie'
            && $request->data()['tmdbId'] === 12345;
    });

    expect($result['id'])->toBe(99);
});

test('updateMovie sends PUT with data', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/42' => Http::response([
            'id' => 42,
            'title' => 'Inception',
            'monitored' => false,
        ]),
    ]);

    $result = $this->client->updateMovie(42, ['monitored' => false]);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'PUT'
            && $request->data()['monitored'] === false;
    });

    expect($result['monitored'])->toBeFalse();
});

test('deleteMovie sends DELETE with deleteFiles param', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/*' => Http::response([], 200),
    ]);

    $this->client->deleteMovie(42, deleteFiles: true);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/v3/movie/42')
            && str_contains($request->url(), 'deleteFiles=true');
    });
});

test('searchMovies encodes query and returns results', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/lookup*' => Http::response([
            ['title' => 'Inception', 'tmdbId' => 27205],
        ]),
    ]);

    $result = $this->client->searchMovies('inception');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'term=inception');
    });

    expect($result)->toHaveCount(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact --filter=RadarrClientTest
```

- [ ] **Step 3: Implement RadarrClient**

Create `app/Services/Radarr/RadarrClient.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Radarr;

use App\Services\Arr\ArrClient;

class RadarrClient extends ArrClient
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMovies(): array
    {
        return $this->buildClient()->get('/api/v3/movie')->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getMovieById(int $id): array
    {
        return $this->buildClient()->get("/api/v3/movie/{$id}")->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function addMovie(array $data): array
    {
        return $this->buildClient()->post('/api/v3/movie', $data)->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function updateMovie(int $id, array $data): array
    {
        return $this->buildClient()->put("/api/v3/movie/{$id}", $data)->throw()->json();
    }

    public function deleteMovie(int $id, bool $deleteFiles = false): void
    {
        $this->buildClient()
            ->delete("/api/v3/movie/{$id}", ['deleteFiles' => $deleteFiles ? 'true' : 'false'])
            ->throw();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchMovies(string $query): array
    {
        return $this->buildClient()->get('/api/v3/movie/lookup', ['term' => $query])->throw()->json();
    }
}
```

- [ ] **Step 4: Run tests**

```bash
vendor/bin/sail artisan test --compact --filter=RadarrClientTest
```

Expected: All 6 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Radarr/ tests/Feature/Services/RadarrClientTest.php
git commit -m "feat: add RadarrClient with movie CRUD

Get, add, update, delete movies. Search by term.
DeleteFiles flag on deletion. Extends ArrClient base."
```

---

## Task 4: EmbyClient + Tests

**Files:**
- Create: `app/Services/Emby/EmbyClient.php`
- Create: `tests/Feature/Services/EmbyClientTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Services/EmbyClientTest.php`:

```php
<?php

use App\Models\ServiceConnection;
use App\Services\Emby\EmbyClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'emby-test-key',
    ]);

    $this->client = new EmbyClient($this->connection);
});

test('sends X-Emby-Token header with requests', function (): void {
    Http::fake([
        'emby.local:8096/System/Info' => Http::response(['ServerName' => 'MyEmby']),
    ]);

    $this->client->getSystemInfo();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Emby-Token', 'emby-test-key'));
});

test('does not send X-Api-Key header', function (): void {
    Http::fake([
        'emby.local:8096/System/Info' => Http::response(['ServerName' => 'MyEmby']),
    ]);

    $this->client->getSystemInfo();

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('X-Api-Key'));
});

test('getSystemInfo returns system data', function (): void {
    Http::fake([
        'emby.local:8096/System/Info' => Http::response([
            'ServerName' => 'MyEmby',
            'Version' => '4.8.0.0',
            'OperatingSystem' => 'Linux',
        ]),
    ]);

    $result = $this->client->getSystemInfo();

    expect($result['ServerName'])->toBe('MyEmby');
    expect($result['Version'])->toBe('4.8.0.0');
});

test('getUsers returns user list', function (): void {
    Http::fake([
        'emby.local:8096/Users' => Http::response([
            ['Id' => 'user-1', 'Name' => 'Admin'],
            ['Id' => 'user-2', 'Name' => 'Guest'],
        ]),
    ]);

    $result = $this->client->getUsers();

    expect($result)->toHaveCount(2);
    expect($result[0]['Name'])->toBe('Admin');
});

test('getUserItems passes query params', function (): void {
    Http::fake([
        'emby.local:8096/Users/user-1/Items*' => Http::response([
            'Items' => [['Id' => 'item-1', 'Name' => 'Movie']],
            'TotalRecordCount' => 1,
        ]),
    ]);

    $result = $this->client->getUserItems('user-1', ['Limit' => 10, 'StartIndex' => 0]);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'Limit=10')
            && str_contains($request->url(), 'StartIndex=0');
    });

    expect($result['TotalRecordCount'])->toBe(1);
});

test('getActiveSessions returns sessions', function (): void {
    Http::fake([
        'emby.local:8096/Sessions' => Http::response([
            ['Id' => 'session-1', 'UserName' => 'Admin', 'NowPlayingItem' => ['Name' => 'Movie']],
        ]),
    ]);

    $result = $this->client->getActiveSessions();

    expect($result)->toHaveCount(1);
    expect($result[0]['UserName'])->toBe('Admin');
});

test('refreshLibrary sends POST', function (): void {
    Http::fake([
        'emby.local:8096/Library/Refresh' => Http::response([], 204),
    ]);

    $this->client->refreshLibrary();

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/Library/Refresh');
    });
});

test('throws on server error', function (): void {
    Http::fake([
        'emby.local:8096/System/Info' => Http::response([], 500),
    ]);

    $this->client->getSystemInfo();
})->throws(RequestException::class);
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact --filter=EmbyClientTest
```

- [ ] **Step 3: Implement EmbyClient**

Create `app/Services/Emby/EmbyClient.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Emby;

use App\Models\ServiceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class EmbyClient
{
    public function __construct(
        protected ServiceConnection $connection,
    ) {}

    protected function buildClient(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->connection->url, '/'))
            ->withHeaders(['X-Emby-Token' => $this->connection->api_key])
            ->timeout(10)
            ->connectTimeout(3)
            ->retry(
                times: 3,
                sleepMilliseconds: [100, 500, 1000],
                when: fn (\Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()),
                throw: false,
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function getSystemInfo(): array
    {
        return $this->buildClient()->get('/System/Info')->throw()->json();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUsers(): array
    {
        return $this->buildClient()->get('/Users')->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserItems(string $userId, array $params = []): array
    {
        return $this->buildClient()->get("/Users/{$userId}/Items", $params)->throw()->json();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getActiveSessions(): array
    {
        return $this->buildClient()->get('/Sessions')->throw()->json();
    }

    public function refreshLibrary(): void
    {
        $this->buildClient()->post('/Library/Refresh')->throw();
    }
}
```

- [ ] **Step 4: Run tests**

```bash
vendor/bin/sail artisan test --compact --filter=EmbyClientTest
```

Expected: All 8 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Emby/ tests/Feature/Services/EmbyClientTest.php
git commit -m "feat: add EmbyClient for Emby server API

System info, users, library items, active sessions, library refresh.
Uses X-Emby-Token header. Same timeout/retry policy as ArrClient."
```

---

## Task 5: JellyseerrClient + Tests

**Files:**
- Create: `app/Services/Jellyseerr/JellyseerrClient.php`
- Create: `tests/Feature/Services/JellyseerrClientTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Services/JellyseerrClientTest.php`:

```php
<?php

use App\Models\ServiceConnection;
use App\Services\Jellyseerr\JellyseerrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->jellyseerr()->create([
        'url' => 'http://jellyseerr.local:5055',
        'api_key' => 'jellyseerr-test-key',
    ]);

    $this->client = new JellyseerrClient($this->connection);
});

test('sends X-Api-Key header with requests', function (): void {
    Http::fake([
        'jellyseerr.local:5055/api/v1/status' => Http::response(['version' => '2.0.0']),
    ]);

    $this->client->getStatus();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Api-Key', 'jellyseerr-test-key'));
});

test('getStatus returns status data', function (): void {
    Http::fake([
        'jellyseerr.local:5055/api/v1/status' => Http::response([
            'version' => '2.0.0',
            'commitTag' => 'abc123',
        ]),
    ]);

    $result = $this->client->getStatus();

    expect($result['version'])->toBe('2.0.0');
});

test('getRequests returns paginated requests', function (): void {
    Http::fake([
        'jellyseerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['pages' => 1, 'results' => 2],
            'results' => [
                ['id' => 1, 'type' => 'movie', 'status' => 2],
                ['id' => 2, 'type' => 'tv', 'status' => 1],
            ],
        ]),
    ]);

    $result = $this->client->getRequests(['take' => 10, 'skip' => 0]);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'take=10');
    });

    expect($result['results'])->toHaveCount(2);
});

test('getRequestById returns single request', function (): void {
    Http::fake([
        'jellyseerr.local:5055/api/v1/request/42' => Http::response([
            'id' => 42,
            'type' => 'movie',
            'media' => ['tmdbId' => 27205],
        ]),
    ]);

    $result = $this->client->getRequestById(42);

    expect($result['id'])->toBe(42);
});

test('deleteRequest sends DELETE', function (): void {
    Http::fake([
        'jellyseerr.local:5055/api/v1/request/42' => Http::response([], 204),
    ]);

    $this->client->deleteRequest(42);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/v1/request/42');
    });
});

test('search encodes query and returns results', function (): void {
    Http::fake([
        'jellyseerr.local:5055/api/v1/search*' => Http::response([
            'results' => [
                ['id' => 1, 'mediaType' => 'movie', 'title' => 'Inception'],
            ],
        ]),
    ]);

    $result = $this->client->search('inception');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'query=inception');
    });

    expect($result['results'])->toHaveCount(1);
});

test('getUsers returns user list', function (): void {
    Http::fake([
        'jellyseerr.local:5055/api/v1/user*' => Http::response([
            'pageInfo' => ['pages' => 1, 'results' => 1],
            'results' => [
                ['id' => 1, 'displayName' => 'Admin'],
            ],
        ]),
    ]);

    $result = $this->client->getUsers();

    expect($result['results'])->toHaveCount(1);
});

test('throws on server error', function (): void {
    Http::fake([
        'jellyseerr.local:5055/api/v1/status' => Http::response([], 500),
    ]);

    $this->client->getStatus();
})->throws(RequestException::class);
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact --filter=JellyseerrClientTest
```

- [ ] **Step 3: Implement JellyseerrClient**

Create `app/Services/Jellyseerr/JellyseerrClient.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Jellyseerr;

use App\Models\ServiceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class JellyseerrClient
{
    public function __construct(
        protected ServiceConnection $connection,
    ) {}

    protected function buildClient(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->connection->url, '/'))
            ->withHeaders(['X-Api-Key' => $this->connection->api_key])
            ->timeout(10)
            ->connectTimeout(3)
            ->retry(
                times: 3,
                sleepMilliseconds: [100, 500, 1000],
                when: fn (\Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()),
                throw: false,
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        return $this->buildClient()->get('/api/v1/status')->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequests(array $params = []): array
    {
        return $this->buildClient()->get('/api/v1/request', $params)->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequestById(int $id): array
    {
        return $this->buildClient()->get("/api/v1/request/{$id}")->throw()->json();
    }

    public function deleteRequest(int $id): void
    {
        $this->buildClient()->delete("/api/v1/request/{$id}")->throw();
    }

    /**
     * @return array<string, mixed>
     */
    public function search(string $query): array
    {
        return $this->buildClient()->get('/api/v1/search', ['query' => $query])->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getUsers(array $params = []): array
    {
        return $this->buildClient()->get('/api/v1/user', $params)->throw()->json();
    }
}
```

- [ ] **Step 4: Run tests**

```bash
vendor/bin/sail artisan test --compact --filter=JellyseerrClientTest
```

Expected: All 8 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Jellyseerr/ tests/Feature/Services/JellyseerrClientTest.php
git commit -m "feat: add JellyseerrClient for Jellyseerr API

Status, requests CRUD, search, users. Uses X-Api-Key header
and /api/v1 prefix. Same timeout/retry policy as other clients."
```

---

## Task 6: Final Verification

- [ ] **Step 1: Run full test suite**

```bash
vendor/bin/sail artisan test --compact
```

Expected: All tests pass (previous 101 + new ~38 service client tests).

- [ ] **Step 2: Run pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 3: Run rector**

```bash
vendor/bin/sail bin rector
```

IMPORTANT: After rector, check that no controller route model binding parameters were renamed. Service client files should be safe since they don't use route model binding.

- [ ] **Step 4: Run tests again**

```bash
vendor/bin/sail artisan test --compact
```

- [ ] **Step 5: Commit formatting if needed**

```bash
git add -A && git status
git commit -m "chore: apply pint and rector formatting"
```
