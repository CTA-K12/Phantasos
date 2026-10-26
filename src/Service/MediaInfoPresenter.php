<?php

namespace App\Service;

use App\Entity\Media;

/**
 * Builds the mediaInfo JSON body exactly as the legacy API did (key order, `size` as
 * `[]` when empty, files only once ready, `original_exists` null until then).
 */
class MediaInfoPresenter
{
    /** @return array<string, mixed> */
    public function toArray(Media $media): array
    {
        $files          = [];
        $originalExists = null;

        if ($media->isReady()) {
            foreach ($media->getFiles() as $file) {
                $size = [];
                if ($file->getWidth() !== null) {
                    $size['width'] = $file->getWidth();
                }
                if ($file->getHeight() !== null) {
                    $size['height'] = $file->getHeight();
                }
                if ($file->getBitRate() !== null) {
                    $size['bitrate'] = $file->getBitRate();
                }
                if ($file->isOriginal()) {
                    $originalExists = true;
                }
                $files[] = [
                    'file_name'     => $file->getFileName(),
                    'content_type'  => $file->getContentType(),
                    'media_file_id' => $file->getId(),
                    'size'          => $size,
                ];
            }
        }

        return [
            'owner'                 => $media->getClient()->getPublicOwnerName(),
            'type'                  => $media->getMediaType()?->value,
            'original_exists'       => $originalExists,
            'tags'                  => $media->getTags(),
            'security'              => $media->getSecurityTags(),
            'files'                 => $files,
            'ready'                 => $media->isReady(),
            'status'                => $media->getStatus()->value,
            'processing_percentage' => self::legacyNumber($media->getProcessingPercentage()),
        ];
    }

    /** PHP 5 encoded whole floats without a fraction (`100`, not `100.0`). */
    private static function legacyNumber(float $value): int|float
    {
        return floor($value) === $value && abs($value) < PHP_INT_MAX ? (int) $value : $value;
    }
}
