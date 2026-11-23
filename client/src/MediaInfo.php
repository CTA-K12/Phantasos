<?php

declare(strict_types=1);

namespace App\Phantasos;

/** What Phantasos knows about a media item (the mediaInfo response). */
final readonly class MediaInfo
{
    /** @param list<MediaFile> $files original first, then renditions; empty until ready */
    public function __construct(
        public string $mediaId,
        /** image, pdf, audio or video; null until a file is uploaded. */
        public ?string $type,
        public ProcessingStatus $status,
        public bool $ready,
        public float $processingPercentage,
        public array $files,
    ) {
    }

    /** @param array<mixed> $data the decoded mediaInfo JSON */
    public static function fromArray(string $mediaId, array $data): self
    {
        $status = is_string($data['status'] ?? null) ? ProcessingStatus::tryFrom($data['status']) : null;
        if ($status === null) {
            throw new PhantasosException('Unexpected mediaInfo response: unknown status.');
        }
        $files = [];
        foreach (is_array($data['files'] ?? null) ? $data['files'] : [] as $file) {
            if (is_array($file)) {
                $files[] = MediaFile::fromArray($file);
            }
        }

        return new self(
            $mediaId,
            is_string($data['type'] ?? null) ? $data['type'] : null,
            $status,
            ($data['ready'] ?? false) === true,
            is_numeric($data['processing_percentage'] ?? null) ? (float) $data['processing_percentage'] : 0.0,
            $files,
        );
    }

    public function original(): ?MediaFile
    {
        foreach ($this->files as $file) {
            if ($file->isOriginal()) {
                return $file;
            }
        }
        return null;
    }

    /** A rendition by name, e.g. `thumbnail.png` or `small.mp4`. */
    public function file(string $fileName): ?MediaFile
    {
        foreach ($this->files as $file) {
            if ($file->fileName === $fileName) {
                return $file;
            }
        }
        return null;
    }

    public function isFailed(): bool
    {
        return $this->status === ProcessingStatus::Failed;
    }
}
