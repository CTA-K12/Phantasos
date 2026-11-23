<?php

declare(strict_types=1);

namespace App\Phantasos;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A file on its way from Phantasos, read in chunks so large videos never sit in memory.
 * Single use: read it once, with toResponse(), saveTo() or contents().
 */
final class FileStream
{
    /** Types a browser may display inline. Everything else is downloaded. */
    private const INLINE_TYPES = ['image/', 'video/', 'audio/', 'application/pdf'];

    private bool $consumed = false;

    /** @param iterable<string> $chunks */
    public function __construct(
        /** 200, or 206 when a Range was requested. */
        public readonly int $status,
        public readonly string $contentType,
        public readonly ?int $contentLength,
        /** `bytes 0-99/5000` for a 206 response. */
        public readonly ?string $contentRange,
        private readonly iterable $chunks,
    ) {
    }

    /**
     * The response to send the user, after the app has checked they may see this file (and
     * written its own audit entry, for PHI). Passes the range status and headers through, never
     * lets the browser or a proxy cache the file, and never lets uploaded content run as part of
     * the app's site.
     */
    public function toResponse(string $downloadName, bool $inline = true): StreamedResponse
    {
        $inline   = $inline && self::canBeInline($this->contentType);
        $response = new StreamedResponse(function (): void {
            foreach ($this->chunks() as $chunk) {
                echo $chunk;
                flush();
            }
        }, $this->status);

        $headers = $response->headers;
        $headers->set('Content-Type', $this->contentType);
        if ($this->contentLength !== null) {
            $headers->set('Content-Length', (string) $this->contentLength);
        }
        if ($this->contentRange !== null) {
            $headers->set('Content-Range', $this->contentRange);
        }
        $headers->set('Accept-Ranges', 'bytes');
        $headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $downloadName,
            self::asciiFallback($downloadName),
        ));
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('X-Content-Type-Options', 'nosniff');
        // Chrome's PDF viewer refuses to render under `sandbox`; PDFs can't run script anyway.
        if ($this->contentType !== 'application/pdf') {
            $headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
        }

        return $response;
    }

    /** Writes the file to disk, e.g. to attach it to an email or run it through another tool. */
    public function saveTo(string $path): void
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new PhantasosException(sprintf('Cannot write "%s".', $path));
        }
        try {
            foreach ($this->chunks() as $chunk) {
                fwrite($handle, $chunk);
            }
        } finally {
            fclose($handle);
        }
    }

    /** The whole file as a string. Only for small files (images, PDFs); stream video instead. */
    public function contents(): string
    {
        $contents = '';
        foreach ($this->chunks() as $chunk) {
            $contents .= $chunk;
        }
        return $contents;
    }

    /** @return iterable<string> */
    private function chunks(): iterable
    {
        if ($this->consumed) {
            throw new \LogicException('A FileStream can only be read once.');
        }
        $this->consumed = true;
        return $this->chunks;
    }

    private static function canBeInline(string $contentType): bool
    {
        foreach (self::INLINE_TYPES as $prefix) {
            if (str_starts_with($contentType, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private static function asciiFallback(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7e]|[%\/\\\\]/', '_', $name);
        return $ascii === null || $ascii === '' ? 'download' : $ascii;
    }
}
