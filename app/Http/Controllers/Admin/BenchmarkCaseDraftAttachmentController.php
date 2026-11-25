<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsageRecord;
use App\Models\ChatAttachment;
use App\Services\Benchmark\CaseDraftFromUsage;
use App\Support\StoredFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BenchmarkCaseDraftAttachmentController extends Controller
{
    /**
     * Preview (images) or download (everything else) a chat attachment that
     * a case drafted from this usage row would copy in. A chat file belongs
     * to its uploader, so an admin reaches it only through the captured
     * conversation (which the admin conversation viewer already shows them),
     * never by its id alone.
     */
    public function __invoke(AiUsageRecord $aiUsageRecord, ChatAttachment $chatAttachment, CaseDraftFromUsage $caseDraftFromUsage): StreamedResponse
    {
        abort_unless(in_array($chatAttachment->id, $caseDraftFromUsage->draftAttachmentIds($aiUsageRecord), true), 404);

        return StoredFileResponse::make($chatAttachment->disk, $chatAttachment->path, $chatAttachment->original_name, $chatAttachment->mime_type);
    }
}
