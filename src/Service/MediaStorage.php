<?php

namespace App\Service;

use App\Entity\Media;
use App\Entity\MediaFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Filesystem side of the media store. Paths follow the legacy layout so files rsynced
 * from the old server are found without renaming.
 */
class MediaStorage
{
    public function __construct(
        private readonly string $mediaRoot,
    ) {
    }

    public function getMediaRoot(): string
    {
        return rtrim($this->mediaRoot, '/');
    }

    public function mediaDirectory(Media $media): string
    {
        return $this->getMediaRoot() . '/' . $media->getStoragePath();
    }

    public function absolutePath(MediaFile $file): string
    {
        return $this->getMediaRoot() . '/' . $file->getStoragePath();
    }

    /**
     * Moves the upload into place as `original.<ext>` and records it as the media's
     * first file. Extension and content type are sniffed from the bytes, not trusted
     * from the client, matching the legacy behaviour.
     */
    public function storeOriginal(Media $media, UploadedFile $upload, string $contentType): MediaFile
    {
        $extension = $upload->guessExtension() ?? 'bin';
        $fileName  = 'original.' . $extension;

        $upload->move($this->mediaDirectory($media), $fileName);

        $file = new MediaFile($media, $fileName, $contentType);
        $file->setOriginal(true);
        $media->addFile($file);

        return $file;
    }

    /** Records a rendition a processor has already written into the media directory. */
    public function addRendition(
        Media $media,
        string $fileName,
        string $contentType,
        ?int $width = null,
        ?int $height = null,
        ?string $bitRate = null,
    ): MediaFile {
        $file = new MediaFile($media, $fileName, $contentType);
        $file->setWidth($width)->setHeight($height)->setBitRate($bitRate);
        $media->addFile($file);

        return $file;
    }
}
