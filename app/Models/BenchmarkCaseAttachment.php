<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BenchmarkCaseAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Override;

/**
 * A file attached to one user turn of a benchmark case; a null turn is the final prompt.
 *
 * @property int $id
 * @property int $benchmark_case_id
 * @property int|null $turn
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read BenchmarkCase $benchmarkCase
 *
 * @method static BenchmarkCaseAttachmentFactory factory($count = null, $state = [])
 * @method static Builder<static>|BenchmarkCaseAttachment newModelQuery()
 * @method static Builder<static>|BenchmarkCaseAttachment newQuery()
 * @method static Builder<static>|BenchmarkCaseAttachment query()
 *
 * @mixin \Eloquent
 */
#[Fillable(['benchmark_case_id', 'turn', 'disk', 'path', 'original_name', 'mime_type', 'size'])]
class BenchmarkCaseAttachment extends Model
{
    /** @use HasFactory<BenchmarkCaseAttachmentFactory> */
    use HasFactory;

    public const string DISK = 'local';

    public const string FINAL_TURN_KEY = 'final';

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'turn' => 'integer',
            'size' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<BenchmarkCase, $this>
     */
    public function benchmarkCase(): BelongsTo
    {
        return $this->belongsTo(BenchmarkCase::class);
    }

    public static function directoryFor(int $benchmarkCaseId): string
    {
        return sprintf('benchmark-cases/%d', $benchmarkCaseId);
    }

    /**
     * Maps a submitted turn key to a turn: 'final' is the prompt (null), a number is a history index.
     *
     * @throws InvalidArgumentException
     */
    public static function turnFromKey(int|string $key): ?int
    {
        if ($key === self::FINAL_TURN_KEY) {
            return null;
        }

        if (is_int($key) || ctype_digit($key)) {
            return (int) $key;
        }

        throw new InvalidArgumentException(sprintf('Unknown attachment turn key "%s".', $key));
    }

    public static function keyForTurn(?int $turn): string
    {
        return $turn === null ? self::FINAL_TURN_KEY : (string) $turn;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function toSdkFile(): File
    {
        return $this->isImage()
            ? Image::fromStorage($this->path, $this->disk)->as($this->original_name)
            : Document::fromStorage($this->path, $this->disk)->as($this->original_name);
    }

    public function turnLabel(): string
    {
        return $this->turn === null ? 'final prompt' : sprintf('turn %d', $this->turn + 1);
    }
}
