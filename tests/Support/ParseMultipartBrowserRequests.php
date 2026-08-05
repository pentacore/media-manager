<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compensates for Pest Browser's embedded Laravel server not populating
 * multipart fields or files (its driver currently leaves the file bag TODO).
 */
final class ParseMultipartBrowserRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $temporaryFiles = [];

        if ($request->request->count() === 0
            && $request->files->count() === 0
            && str_starts_with(strtolower((string) $request->header('content-type')), 'multipart/form-data')) {
            $temporaryFiles = $this->parse($request);
        }

        try {
            return $next($request);
        } finally {
            foreach ($temporaryFiles as $temporaryFile) {
                if (is_file($temporaryFile)) {
                    unlink($temporaryFile);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function parse(Request $request): array
    {
        $contentType = (string) $request->header('content-type');

        if (preg_match('/boundary=(?:"([^"]+)"|([^;]+))/i', $contentType, $matches) !== 1) {
            return [];
        }

        $boundary = $matches[1] !== '' ? $matches[1] : trim($matches[2]);
        $temporaryFiles = [];

        foreach (explode('--'.$boundary, $request->getContent()) as $part) {
            $part = ltrim($part, "\r\n");
            if ($part === '') {
                continue;
            }

            if (str_starts_with($part, '--')) {
                continue;
            }

            [$rawHeaders, $body] = array_pad(explode("\r\n\r\n", $part, 2), 2, null);
            if (! is_string($rawHeaders)) {
                continue;
            }

            if (! is_string($body)) {
                continue;
            }

            if (preg_match('/name="([^"]+)"/i', $rawHeaders, $nameMatch) !== 1) {
                continue;
            }

            $name = $nameMatch[1];
            $body = preg_replace("/\r\n$/", '', $body) ?? $body;

            if (preg_match('/filename="([^"]*)"/i', $rawHeaders, $filenameMatch) !== 1) {
                $request->request->set($name, $body);

                continue;
            }

            $temporaryFile = tempnam(sys_get_temp_dir(), 'pest-upload-');

            if ($temporaryFile === false) {
                continue;
            }

            file_put_contents($temporaryFile, $body);
            $temporaryFiles[] = $temporaryFile;
            preg_match('/content-type:\s*([^\r\n]+)/i', $rawHeaders, $mimeMatch);
            $request->files->set($name, new UploadedFile(
                $temporaryFile,
                $filenameMatch[1],
                $mimeMatch[1] ?? 'application/octet-stream',
                test: true,
            ));
        }

        return $temporaryFiles;
    }
}
