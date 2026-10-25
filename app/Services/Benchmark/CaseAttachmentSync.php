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
     */
    public function sync(BenchmarkCase $benchmarkCase, ?array $keep, array $drafts, array $uploads): int
    {
        $removedPaths = [];

        $skipped = DB::transaction(function () use ($benchmarkCase, $keep, $drafts, $uploads, &$removedPaths): int {
            if ($keep !== null) {
                $removedPaths = $this->reconcileKept($benchmarkCase, $keep);
            }

            $skipped = 0;

            foreach ($drafts as $key => $ids) {
                foreach ($ids as $id) {
                    if (! $this->copyDraft($benchmarkCase, BenchmarkCaseAttachment::turnFromKey($key), $id)) {
                        $skipped++;
                    }
                }
            }

            foreach ($uploads as $key => $files) {
                foreach ($files as $file) {
                    $this->storeUpload($benchmarkCase, BenchmarkCaseAttachment::turnFromKey($key), $file);
                }
            }

            return $skipped;
        });

        if ($removedPaths !== []) {
            DB::afterCommit(static fn (): bool => Storage::disk(BenchmarkCaseAttachment::DISK)->delete($removedPaths));
        }

        return $skipped;
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

    private function copyDraft(BenchmarkCase $benchmarkCase, ?int $turn, int $chatAttachmentId): bool
    {
        $chatAttachment = ChatAttachment::query()->find($chatAttachmentId);

        if (! $chatAttachment instanceof ChatAttachment) {
            return false;
        }

        $filesystem = Storage::disk($chatAttachment->disk);

        if (! $filesystem->exists($chatAttachment->path)) {
            return false;
        }

        $extension = pathinfo($chatAttachment->path, PATHINFO_EXTENSION);
        $path = sprintf('%s/%s.%s', BenchmarkCaseAttachment::directoryFor($benchmarkCase->id), Str::uuid7(), $extension === '' ? 'bin' : $extension);

        Storage::disk(BenchmarkCaseAttachment::DISK)->writeStream($path, $filesystem->readStream($chatAttachment->path));

        $benchmarkCase->attachments()->create([
            'turn' => $turn,
            'disk' => BenchmarkCaseAttachment::DISK,
            'path' => $path,
            'original_name' => $chatAttachment->original_name,
            'mime_type' => $chatAttachment->mime_type,
            'size' => $chatAttachment->size,
        ]);

        return true;
    }

    private function storeUpload(BenchmarkCase $benchmarkCase, ?int $turn, UploadedFile $file): void
    {
        $extension = $file->extension();
        $path = $file->storeAs(
            BenchmarkCaseAttachment::directoryFor($benchmarkCase->id),
            sprintf('%s.%s', Str::uuid7(), $extension === '' ? 'bin' : $extension),
            BenchmarkCaseAttachment::DISK,
        );

        $benchmarkCase->attachments()->create([
            'turn' => $turn,
            'disk' => BenchmarkCaseAttachment::DISK,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
        ]);
    }
}
