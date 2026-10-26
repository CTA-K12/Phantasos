<?php

namespace App\Processor;

use App\Entity\Media;
use App\Enum\MediaType;
use App\Service\MediaStorage;
use App\Service\Processing\ProgressReporter;

/**
 * Four PNG renditions. Like the legacy Imagine resize, each is scaled to exactly the
 * box size (aspect ratio is not preserved); consumers were built against that output.
 */
class ImageProcessor implements ProcessorInterface
{
    /** name => [width, height], in legacy order. */
    public const RENDITIONS = [
        'thumbnail' => [75, 75],
        'small'     => [320, 240],
        'medium'    => [640, 480],
        'large'     => [1024, 768],
    ];

    public function __construct(
        private readonly MediaStorage $storage,
    ) {
    }

    public function getMediaType(): MediaType
    {
        return MediaType::Image;
    }

    public function getSupportedMimeTypes(): array
    {
        return [
            'image/bmp', 'image/x-windows-bmp', 'image/x-ms-bmp',
            'image/jpeg', 'image/pjpeg',
            'image/png',
            'image/x-xbitmap', 'image/x-xbm', 'image/xbm',
            'image/xpm', 'image/x-xpixmap',
        ];
    }

    public function process(Media $media, ProgressReporter $progress): void
    {
        $original = $media->getOriginalFile() ?? throw new \RuntimeException('Missing original file.');
        $source   = $this->load($this->storage->absolutePath($original), $original->getContentType());
        $dir      = $this->storage->mediaDirectory($media);

        $progress->setPhases(count(self::RENDITIONS));
        try {
            foreach (self::RENDITIONS as $name => [$width, $height]) {
                $progress->startPhase();
                $target = imagecreatetruecolor($width, $height);
                if ($target === false) {
                    throw new \RuntimeException('Could not allocate image.');
                }
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
                if (!imagepng($target, $dir . $name . '.png')) {
                    throw new \RuntimeException(sprintf('Could not write %s.png', $name));
                }
                $this->storage->addRendition($media, $name . '.png', 'image/png', $width, $height, '72ppi');
                $progress->endPhase();
            }
        } finally {
            unset($source);
        }
    }

    private function load(string $path, string $mimeType): \GdImage
    {
        $image = match ($mimeType) {
            'image/x-xbitmap', 'image/x-xbm', 'image/xbm' => @imagecreatefromxbm($path),
            'image/xpm', 'image/x-xpixmap'                 => function_exists('imagecreatefromxpm') ? @imagecreatefromxpm($path) : false,
            default                                        => @imagecreatefromstring((string) file_get_contents($path)),
        };
        if (!$image instanceof \GdImage) {
            throw new \RuntimeException(sprintf('Could not decode %s image.', $mimeType));
        }
        return $image;
    }
}
