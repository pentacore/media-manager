<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IndexedSeriesFactory;
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
 * @property int $sonarr_id
 * @property int|null $tvdb_id
 * @property int|null $imdb_id
 * @property string $title
 * @property string|null $sort_title
 * @property int|null $year
 * @property string|null $title_slug
 * @property string|null $status
 * @property bool $monitored
 * @property string|null $network
 * @property array<int, string>|null $genres
 * @property string|null $overview
 * @property string|null $poster_url
 * @property CarbonImmutable|null $arr_added_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ServiceConnection $serviceConnection
 *
 * @method static IndexedSeriesFactory factory($count = null, $state = [])
 * @method static Builder<static>|IndexedSeries newModelQuery()
 * @method static Builder<static>|IndexedSeries newQuery()
 * @method static Builder<static>|IndexedSeries query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'service_connection_id',
    'sonarr_id',
    'tvdb_id',
    'imdb_id',
    'title',
    'sort_title',
    'year',
    'title_slug',
    'status',
    'monitored',
    'network',
    'genres',
    'overview',
    'poster_url',
    'arr_added_at',
])]
#[Table(name: 'indexed_series')]
class IndexedSeries extends Model
{
    /** @use HasFactory<IndexedSeriesFactory> */
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
            'genres' => 'array',
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
        return config('scout.prefix', '').'series';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'service_connection_id' => (int) $this->service_connection_id,
            'sonarr_id' => (int) $this->sonarr_id,
            'tvdb_id' => $this->tvdb_id !== null ? (int) $this->tvdb_id : null,
            'title' => (string) $this->title,
            'sort_title' => (string) ($this->sort_title ?? $this->title),
            'year' => $this->year !== null ? (int) $this->year : null,
            'title_slug' => (string) ($this->title_slug ?? ''),
            'status' => (string) ($this->status ?? ''),
            'monitored' => (bool) $this->monitored,
            'network' => (string) ($this->network ?? ''),
            'genres' => $this->genres ?? [],
            'overview' => (string) ($this->overview ?? ''),
            'poster_url' => (string) ($this->poster_url ?? ''),
            'created_at' => (int) ($this->created_at?->timestamp ?? 0),
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->status !== 'deleted';
    }
}
