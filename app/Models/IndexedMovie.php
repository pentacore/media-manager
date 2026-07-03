<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IndexedMovieFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;
use Override;

/**
 * @property int $id
 * @property int $service_connection_id
 * @property int $radarr_id
 * @property int|null $tmdb_id
 * @property string|null $imdb_id
 * @property string $title
 * @property string|null $sort_title
 * @property string|null $original_title
 * @property int|null $year
 * @property string|null $title_slug
 * @property string|null $status
 * @property bool $monitored
 * @property bool $has_file
 * @property array<int, string>|null $genres
 * @property string|null $overview
 * @property string|null $poster_url
 * @property array<int, float>|null $embedding
 * @property CarbonImmutable|null $arr_added_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ServiceConnection $serviceConnection
 *
 * @method static IndexedMovieFactory factory($count = null, $state = [])
 * @method static Builder<static>|IndexedMovie newModelQuery()
 * @method static Builder<static>|IndexedMovie newQuery()
 * @method static Builder<static>|IndexedMovie query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'service_connection_id',
    'radarr_id',
    'tmdb_id',
    'imdb_id',
    'title',
    'sort_title',
    'original_title',
    'year',
    'title_slug',
    'status',
    'monitored',
    'has_file',
    'genres',
    'overview',
    'poster_url',
    'embedding',
    'arr_added_at',
])]
#[Table(name: 'indexed_movies')]
class IndexedMovie extends Model
{
    /** @use HasFactory<IndexedMovieFactory> */
    use HasFactory;

    use Searchable;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'monitored' => 'boolean',
            'has_file' => 'boolean',
            'genres' => 'array',
            'embedding' => 'array',
            'arr_added_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ServiceConnection, $this>
     */
    public function serviceConnection(): BelongsTo
    {
        return $this->belongsTo(ServiceConnection::class);
    }

    public function searchableAs(): string
    {
        return config('scout.prefix', '').'movies';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $searchable = [
            'id' => (string) $this->id,
            'service_connection_id' => (int) $this->service_connection_id,
            'radarr_id' => (int) $this->radarr_id,
            'tmdb_id' => $this->tmdb_id !== null ? (int) $this->tmdb_id : null,
            'imdb_id' => (string) ($this->imdb_id ?? ''),
            'title' => (string) $this->title,
            'sort_title' => (string) ($this->sort_title ?? $this->title),
            'original_title' => (string) ($this->original_title ?? ''),
            'year' => $this->year !== null ? (int) $this->year : null,
            'title_slug' => (string) ($this->title_slug ?? ''),
            'status' => (string) ($this->status ?? ''),
            'monitored' => (bool) $this->monitored,
            'has_file' => (bool) $this->has_file,
            'genres' => $this->genres ?? [],
            'overview' => (string) ($this->overview ?? ''),
            'poster_url' => (string) ($this->poster_url ?? ''),
            'created_at' => (int) ($this->created_at?->timestamp ?? 0),
        ];

        if (is_array($this->embedding) && $this->embedding !== []) {
            $searchable['embedding'] = array_map(static fn ($v): float => (float) $v, $this->embedding);
        }

        return $searchable;
    }

    public function shouldBeSearchable(): bool
    {
        return $this->status !== 'deleted';
    }
}
