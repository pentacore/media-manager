<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseAttachment;
use App\Models\ChatAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Brings a case's attachments in line with a saved form: keeps and renumbers the
 * ones the form still lists, copies draft chat files in, and stores new uploads.
 */
final readonly class CaseAttachmentSync
{
    /**
     * @param  array<array-key, list<int>>|null  $keep  attachment ids to keep per turn key; null leaves the case's attachments alone
     * @param  array<array-key, list<int>>  $drafts  chat attachment ids to copy per turn key
     * @param  array<array-key, list<UploadedFile>>  $uploads  new files per turn key
     * @return int how many draft copies were skipped because the chat file is gone
     *
     * @throws RuntimeException when an upload could not be stored; the files written so far are removed
     */
    public function sync(BenchmarkCase $benchmarkCase, ?array $keep, array $drafts, array $uploads): int
    {
        $removedPaths = [];
        $writtenPaths = [];

        try {
            $skipped = $this->apply($benchmarkCase, $keep, $drafts, $uploads, $removedPaths, $writtenPaths);
        } catch (Throwable $throwable) {
            $this->deleteFiles($writtenPaths);

            throw $throwable;
        }

        // A rollback of an enclosing transaction (the controller's) drops the
        // rows, so the files written for them go too.
        if ($writtenPaths !== []) {
            DB::afterRollBack(fn () => $this->deleteFiles($writtenPaths));
        }

        if ($removedPaths !== []) {
            DB::afterCommit(fn () => $this->deleteFiles($removedPaths));
        }

        return $skipped;
    }

    /**
     * @param  array<array-key, list<int>>|null  $keep
     * @param  array<array-key, list<int>>  $drafts
     * @param  array<array-key, list<UploadedFile>>  $uploads
     * @param  list<string>  $removedPaths
     * @param  list<string>  $writtenPaths
     */
    private function apply(BenchmarkCase $benchmarkCase, ?array $keep, array $drafts, array $uploads, array &$removedPaths, array &$writtenPaths): int
    {
        return DB::transaction(function () use ($benchmarkCase, $keep, $drafts, $uploads, &$removedPaths, &$writtenPaths): int {
            if ($keep !== null) {
                $removedPaths = $this->reconcileKept($benchmarkCase, $keep);
            }

            $skipped = 0;

            foreach ($drafts as $key => $ids) {
                foreach ($ids as $id) {
                    $path = $this->copyDraft($benchmarkCase, BenchmarkCaseAttachment::turnFromKey($key), $id);

                    if ($path === null) {
                        $skipped++;

                        continue;
                    }

                    $writtenPaths[] = $path;
                }
            }

            foreach ($uploads as $key => $files) {
                foreach ($files as $file) {
                    $writtenPaths[] = $this->storeUpload($benchmarkCase, BenchmarkCaseAttachment::turnFromKey($key), $file);
                }
            }

            return $skipped;
        });
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteFiles(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk(BenchmarkCaseAttachment::DISK)->delete($paths);
        }
    }

    /**
     * @param  array<array-key, list<int>>  $keep
     * @return list<string> the paths of the deleted attachments
     */
    private function reconcileKept(BenchmarkCase $benchmarkCase, array $keep): array
    {
        $keptTurns = [];

        foreach ($keep as $key => $ids) {
            foreach ($ids as $id) {
                $keptTurns[$id] = BenchmarkCaseAttachment::turnFromKey($key);
            }
        }

        $removedPaths = [];

        foreach ($benchmarkCase->attachments()->get() as $attachment) {
            if (array_key_exists($attachment->id, $keptTurns)) {
                if ($attachment->turn !== $keptTurns[$attachment->id]) {
                    $attachment->update(['turn' => $keptTurns[$attachment->id]]);
                }

                continue;
            }

            $removedPaths[] = $attachment->path;
            $attachment->delete();
        }

        return $removedPaths;
    }

    /**
     * @return string|null the stored copy's path, or null when the chat file is gone
     */
    private function copyDraft(BenchmarkCase $benchmarkCase, ?int $turn, int $chatAttachmentId): ?string
    {
        $chatAttachment = ChatAttachment::query()->find($chatAttachmentId);

        if (! $chatAttachment instanceof ChatAttachment) {
            return null;
        }

        $filesystem = Storage::disk($chatAttachment->disk);
        $stream = $filesystem->exists($chatAttachment->path) ? $filesystem->readStream($chatAttachment->path) : null;

        if (! is_resource($stream)) {
            return null;
        }

        $path = sprintf('%s/%s.%s', BenchmarkCaseAttachment::directoryFor($benchmarkCase->id), Str::uuid7(), pathinfo($chatAttachment->path, PATHINFO_EXTENSION) ?: 'bin');

        try {
            $written = Storage::disk(BenchmarkCaseAttachment::DISK)->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }

        throw_unless($written, RuntimeException::class, sprintf('The attachment %s could not be stored.', $chatAttachment->original_name));

        $benchmarkCase->attachments()->create([
            'turn' => $turn,
            'disk' => BenchmarkCaseAttachment::DISK,
            'path' => $path,
            'original_name' => $chatAttachment->original_name,
            'mime_type' => $chatAttachment->mime_type,
            'size' => $chatAttachment->size,
        ]);

        return $path;
    }

    /**
     * @return string the stored file's path
     */
    private function storeUpload(BenchmarkCase $benchmarkCase, ?int $turn, UploadedFile $file): string
    {
        $path = $file->storeAs(
            BenchmarkCaseAttachment::directoryFor($benchmarkCase->id),
            sprintf('%s.%s', Str::uuid7(), $file->extension() ?: 'bin'),
            BenchmarkCaseAttachment::DISK,
        );

        throw_unless(is_string($path), RuntimeException::class, sprintf('The attachment %s could not be stored.', $file->getClientOriginalName()));

        $benchmarkCase->attachments()->create([
            'turn' => $turn,
            'disk' => BenchmarkCaseAttachment::DISK,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
        ]);

        return $path;
    }
}
