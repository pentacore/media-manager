<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Models\ChatAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatAttachmentController extends Controller
{
    /**
     * Download a chat attachment. Only its uploader may read it.
     */
    public function __invoke(Request $request, ChatAttachment $chatAttachment): StreamedResponse
    {
        abort_unless($chatAttachment->user_id === $request->user()?->id, 403);

        return Storage::disk($chatAttachment->disk)->response($chatAttachment->path, $chatAttachment->original_name);
    }
}
