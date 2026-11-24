<?php

namespace App\Processor;

use App\Entity\Media;
use App\Enum\MediaType;
use App\Service\MediaStorage;
use App\Service\Processing\Ffmpeg;
use App\Service\Processing\ProgressReporter;

/**
 * Four PNG renditions. Like the legacy Imagine resize, each is scaled to exactly the
 * box size (aspect ratio is not preserved); consumers were built against that output.
 *
 * GD decodes most formats itself; HEIC/HEIF (iPhone photos) and TIFF (scanners, faxes) are
 * decoded by ffmpeg first. Photos are turned upright: JPEGs from their EXIF orientation, HEIC
 * by ffmpeg. The renditions carry no metadata (no camera details or location).
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

    /** Decoded by ffmpeg, not GD. */
    private const VIA_FFMPEG = ['image/heic', 'image/heif', 'image/tiff'];

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly Ffmpeg $ffmpeg,
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
            // Added 2026-11-22
            'image/gif', 'image/webp', 'image/avif',
            'image/heic', 'image/heif',
            'image/tiff',
        ];
    }

    public function process(Media $media, ProgressReporter $progress): void
    {
        $original    = $media->getOriginalFile() ?? throw new \RuntimeException('Missing original file.');
        $path        = $this->storage->absolutePath($original);
        $source      = $this->load($path, $original->getContentType(), $this->storage->mediaDirectory($media));
        $orientation = $this->orientation($path, $original->getContentType());
        $dir         = $this->storage->mediaDirectory($media);

        $progress->setPhases(count(self::RENDITIONS));
        try {
            foreach (self::RENDITIONS as $name => [$width, $height]) {
                $progress->startPhase();
                // A quarter-turned photo is resampled into the turned box, then turned upright,
                // so the rendition still has the advertised size.
                $quarterTurn = $orientation >= 5;
                $target      = imagecreatetruecolor($quarterTurn ? $height : $width, $quarterTurn ? $width : $height);
                if ($target === false) {
                    throw new \RuntimeException('Could not allocate image.');
                }
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), imagesx($source), imagesy($source));
                $target = self::upright($target, $orientation);
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

    private function load(string $path, string $mimeType, string $workDir): \GdImage
    {
        if (in_array($mimeType, self::VIA_FFMPEG, true)) {
            $decoded = $workDir . '.decoded.png';
            try {
                $this->ffmpeg->decodeImage($path, $decoded);
                return $this->load($decoded, 'image/png', $workDir);
            } finally {
                @unlink($decoded);
            }
        }

        $image = match ($mimeType) {
            'image/x-xbitmap', 'image/x-xbm', 'image/xbm' => @imagecreatefromxbm($path),
            'image/xpm', 'image/x-xpixmap'                 => function_exists('imagecreatefromxpm') ? @imagecreatefromxpm($path) : false,
            // JPEG, PNG, GIF (first frame), BMP, WebP, AVIF
            default                                        => @imagecreatefromstring((string) file_get_contents($path)),
        };
        if (!$image instanceof \GdImage) {
            throw new \RuntimeException(sprintf('Could not decode %s image.', $mimeType));
        }
        // Palette images (GIF, some PNGs) resample with their transparency kept.
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        return $image;
    }

    /** EXIF orientation 1–8 (1 = already upright). Only JPEGs need it; ffmpeg turns HEIC itself. */
    private function orientation(string $path, string $mimeType): int
    {
        if (!in_array($mimeType, ['image/jpeg', 'image/pjpeg'], true) || !function_exists('exif_read_data')) {
            return 1;
        }
        $exif  = @exif_read_data($path, 'IFD0');
        $value = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;
        return is_int($value) && $value >= 1 && $value <= 8 ? $value : 1;
    }

    /** Applies an EXIF orientation. imagerotate() turns counter-clockwise. */
    private static function upright(\GdImage $image, int $orientation): \GdImage
    {
        $rotate = static function (\GdImage $image, int $degrees): \GdImage {
            $rotated = imagerotate($image, $degrees, 0);
            if ($rotated === false) {
                throw new \RuntimeException('Could not rotate image.');
            }
            imagesavealpha($rotated, true);
            return $rotated;
        };

        switch ($orientation) {
            case 2: imageflip($image, IMG_FLIP_HORIZONTAL); return $image;
            case 3: return $rotate($image, 180);
            case 4: imageflip($image, IMG_FLIP_VERTICAL); return $image;
            case 5: $image = $rotate($image, 270); imageflip($image, IMG_FLIP_HORIZONTAL); return $image; // transpose
            case 6: return $rotate($image, 270);                                                          // 90° clockwise
            case 7: $image = $rotate($image, 90); imageflip($image, IMG_FLIP_HORIZONTAL); return $image;  // transverse
            case 8: return $rotate($image, 90);                                                           // 90° counter-clockwise
            default: return $image;
        }
    }
}
