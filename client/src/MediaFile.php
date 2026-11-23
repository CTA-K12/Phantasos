<?php

declare(strict_types=1);

namespace App\Phantasos;

/**
 * One file of a media item: the original or a rendition. File names are fixed by type, e.g.
 * `thumbnail.png`, `small.png`, `medium.png`, `large.png` for images; `small.mp4`, `large.mp4` and
 * their `-frame.png` posters for video; `low.mp3`, `high.mp3` for audio. PDFs have only the original.
 */
final readonly class MediaFile
{
    public function __construct(
        public string $id,
        public string $fileName,
        public string $contentType,
        public ?int $width = null,
        public ?int $height = null,
        /** As Phantasos reports it: kbps for audio/video (`"750"`), resolution for images (`"72ppi"`). */
        public ?string $bitRate = null,
    ) {
    }

    /** @param array<mixed> $file one entry of the mediaInfo `files` list */
    public static function fromArray(array $file): self
    {
        $size = is_array($file['size'] ?? null) ? $file['size'] : [];

        return new self(
            self::string($file, 'media_file_id'),
            self::string($file, 'file_name'),
            self::string($file, 'content_type'),
            isset($size['width']) && is_numeric($size['width']) ? (int) $size['width'] : null,
            isset($size['height']) && is_numeric($size['height']) ? (int) $size['height'] : null,
            isset($size['bitrate']) && is_scalar($size['bitrate']) ? (string) $size['bitrate'] : null,
        );
    }

    public function isOriginal(): bool
    {
        return str_starts_with($this->fileName, 'original.');
    }

    /** @param array<mixed> $data */
    private static function string(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new PhantasosException(sprintf('Unexpected mediaInfo response: file "%s" is missing.', $key));
        }
        return $data[$key];
    }
}
