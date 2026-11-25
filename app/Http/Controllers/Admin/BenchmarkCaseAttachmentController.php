<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseAttachment;
use App\Support\StoredFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BenchmarkCaseAttachmentController extends Controller
{
    /**
     * Preview (images) or download (everything else) one attachment of a case.
     */
    public function __invoke(BenchmarkCase $benchmarkCase, BenchmarkCaseAttachment $attachment): StreamedResponse
    {
        return StoredFileResponse::make($attachment->disk, $attachment->path, $attachment->original_name, $attachment->mime_type);
    }
}
