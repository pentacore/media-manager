<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Models\ChatAttachment;
use App\Support\StoredFileResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatAttachmentController extends Controller
{
    /**
     * Download a chat attachment (images render inline for chat thumbnails).
     * Only its uploader may read it.
     */
    public function __invoke(Request $request, ChatAttachment $chatAttachment): StreamedResponse
    {
        abort_unless($chatAttachment->user_id === $request->user()?->id, 403);

        return StoredFileResponse::make($chatAttachment->disk, $chatAttachment->path, $chatAttachment->original_name, $chatAttachment->mime_type);
    }
}
