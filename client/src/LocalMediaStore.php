<?php

declare(strict_types=1);

namespace App\Phantasos;

/**
 * A MediaStore on the local disk, for development and tests: no Phantasos server, no API key,
 * no network. It accepts the same file types Phantasos does (ACCEPTED_TYPES) and rejects the rest
 * the same way, but makes no renditions: every item is Ready at once with only its original.
 *
 * Never use it in production: nothing here is encrypted, audited or backed up.
 */
final class LocalMediaStore implements MediaStore
{
    private const ID     = '/^[0-9a-f]{24}$/';
    private const CHUNK  = 65536;

    public function __construct(private readonly string $directory)
    {
    }

    public function upload(string $path, ?string $fileName = null, ?string $callbackUrl = null): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('Cannot read "%s".', $path));
        }
        $contentType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($contentType, self::ACCEPTED_TYPES, true)) {
            throw new UploadRejectedException(sprintf('Filetype "%s" is not supported.', $contentType));
        }

        $mediaId   = bin2hex(random_bytes(12));
        $fileId    = bin2hex(random_bytes(12));
        $extension = strtolower(pathinfo($fileName ?? $path, PATHINFO_EXTENSION));
        $stored    = 'original' . ($extension !== '' ? '.' . $extension : '');

        $dir = $this->directory . '/' . $mediaId;
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new PhantasosException(sprintf('Cannot create "%s".', $dir));
        }
        if (!copy($path, $dir . '/' . $stored)) {
            throw new PhantasosException('Cannot store the file.');
        }
        $this->writeJson($dir . '/media.json', ['file_id' => $fileId, 'file_name' => $stored, 'content_type' => $contentType]);
        $this->writeJson($this->directory . '/files/' . $fileId . '.json', ['media_id' => $mediaId]);

        return $mediaId;
    }

    public function info(string $mediaId): MediaInfo
    {
        $meta = $this->media($mediaId);

        return new MediaInfo(
            $mediaId,
            self::typeOf($meta['content_type']),
            ProcessingStatus::Ready,
            true,
            100.0,
            [new MediaFile($meta['file_id'], $meta['file_name'], $meta['content_type'])],
        );
    }

    public function fetch(string $mediaFileId, ?string $range = null): FileStream
    {
        $index = preg_match(self::ID, $mediaFileId) ? $this->readJson($this->directory . '/files/' . $mediaFileId . '.json') : null;
        $mediaId = is_string($index['media_id'] ?? null) ? $index['media_id'] : '';
        $meta = $this->media($mediaId);
        $path = $this->directory . '/' . $mediaId . '/' . $meta['file_name'];
        $size = (int) filesize($path);

        $start = 0;
        $end   = $size - 1;
        $status = 200;
        $contentRange = null;
        if ($range !== null && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {                     // bytes=-500: the last 500 bytes
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                $end   = $m[2] === '' ? $end : min($end, (int) $m[2]);
            }
            if ($start > $end || $start >= $size) {
                throw new PhantasosException('requestMedia failed: HTTP 416 Range Not Satisfiable');
            }
            $status       = 206;
            $contentRange = sprintf('bytes %d-%d/%d', $start, $end, $size);
        }

        $length = $end - $start + 1;
        $chunks = (static function () use ($path, $start, $length): \Generator {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new PhantasosException('Cannot read the stored file.');
            }
            try {
                fseek($handle, $start);
                for ($left = $length; $left > 0 && !feof($handle); $left -= strlen($chunk)) {
                    $chunk = (string) fread($handle, max(1, min(self::CHUNK, $left)));
                    yield $chunk;
                }
            } finally {
                fclose($handle);
            }
        })();

        return new FileStream($status, $meta['content_type'], $length, $contentRange, $chunks);
    }

    /** @return array{file_id: string, file_name: string, content_type: string} */
    private function media(string $mediaId): array
    {
        $meta = preg_match(self::ID, $mediaId) ? $this->readJson($this->directory . '/' . $mediaId . '/media.json') : null;
        if (!is_string($meta['file_id'] ?? null) || !is_string($meta['file_name'] ?? null) || !is_string($meta['content_type'] ?? null)) {
            throw new MediaNotFoundException('Media was not found');
        }
        return ['file_id' => $meta['file_id'], 'file_name' => $meta['file_name'], 'content_type' => $meta['content_type']];
    }

    private static function typeOf(string $contentType): string
    {
        return match (true) {
            in_array($contentType, self::IMAGE_TYPES, true) => 'image',
            in_array($contentType, self::AUDIO_TYPES, true) => 'audio',
            in_array($contentType, self::VIDEO_TYPES, true) => 'video',
            default                                         => 'pdf',
        };
    }

    /** @param array<string, string> $data */
    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new PhantasosException(sprintf('Cannot create "%s".', $dir));
        }
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return array<mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }
}
