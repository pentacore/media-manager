<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BenchmarkCaseAttachmentController extends Controller
{
    /**
     * Raster formats safe to render inline. Anything else (PDF, text) is
     * forced to download so stored uploads never execute on the app origin.
     */
    private const array INLINE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /**
     * Preview (images) or download (everything else) one attachment of a case.
     */
    public function __invoke(BenchmarkCase $benchmarkCase, BenchmarkCaseAttachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        return in_array($attachment->mime_type, self::INLINE_MIME_TYPES, true)
            ? $disk->response($attachment->path, $attachment->original_name, $headers)
            : $disk->download($attachment->path, $attachment->original_name, $headers);
    }
}
