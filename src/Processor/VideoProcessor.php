<?php

namespace App\Processor;

use App\Entity\Media;
use App\Enum\MediaType;
use App\Service\MediaStorage;
use App\Service\Processing\Ffmpeg;
use App\Service\Processing\ProgressReporter;

/**
 * Per size: a poster frame and an H.264/AAC MP4 at the legacy dimensions and bitrates.
 * The legacy WebM/Ogg copies are no longer produced (MP4 plays everywhere; consumers
 * reference `small.*` through <source> lists and pick up whatever exists).
 *
 * Output is letterboxed to exactly the advertised size, so mediaInfo's width/height
 * stay true. A "video" with no picture (e.g. audio-only WebM) is handed to the audio
 * processor and re-typed as audio, as legacy did.
 */
class VideoProcessor implements ProcessorInterface
{
    /** name => [width, height, video kbit/s, audio kbit/s], in legacy order. */
    public const RENDITIONS = [
        'small' => [640, 360, 750, 48],
        'large' => [1280, 720, 2500, 96],
    ];

    private const FRAME_AT_SECONDS = 10.0;

    public function __construct(
        private readonly Ffmpeg $ffmpeg,
        private readonly MediaStorage $storage,
        private readonly AudioProcessor $audioProcessor,
    ) {
    }

    public function getMediaType(): MediaType
    {
        return MediaType::Video;
    }

    public function getSupportedMimeTypes(): array
    {
        return [
            'video/x-ms-asf', 'video/avi', 'video/msvideo', 'video/x-msvideo', 'video/x-ms-wmv',
            'video/x-dv', 'video/x-flv', 'video/x-m4v', 'video/x-matroska', 'video/quicktime',
            'video/mp4', 'video/mpeg', 'video/ogg', 'video/webm', 'application/x-winamp', 'video/3gpp',
        ];
    }

    public function process(Media $media, ProgressReporter $progress): void
    {
        $original = $media->getOriginalFile() ?? throw new \RuntimeException('Missing original file.');
        $input    = $this->storage->absolutePath($original);
        $probe    = $this->ffmpeg->probe($input);

        if (!$probe['hasVideo']) {
            $media->setMediaType(MediaType::Audio);
            $this->audioProcessor->process($media, $progress);
            return;
        }

        $dir     = $this->storage->mediaDirectory($media);
        $frameAt = $probe['duration'] > self::FRAME_AT_SECONDS ? self::FRAME_AT_SECONDS : $probe['duration'] / 2;

        $progress->setPhases(count(self::RENDITIONS));
        foreach (self::RENDITIONS as $name => [$width, $height, $videoKbps, $audioKbps]) {
            $progress->startPhase();
            $fit = sprintf(
                'scale=%1$d:%2$d:force_original_aspect_ratio=decrease,pad=%1$d:%2$d:(ow-iw)/2:(oh-ih)/2,setsar=1',
                $width,
                $height,
            );

            $this->ffmpeg->encode($input, [
                '-ss', sprintf('%.3f', $frameAt), '-frames:v', '1', '-vf', $fit, '-an',
                $dir . $name . '-frame.png',
            ], 0);

            $audio = $probe['hasAudio']
                ? ['-c:a', 'aac', '-b:a', $audioKbps . 'k', '-ac', '2']
                : ['-an'];
            $this->ffmpeg->encode($input, [
                '-map', '0:v:0', ...($probe['hasAudio'] ? ['-map', '0:a:0'] : []),
                '-vf', $fit,
                '-c:v', 'libx264', '-preset', 'medium', '-profile:v', 'high', '-pix_fmt', 'yuv420p',
                '-b:v', $videoKbps . 'k', '-maxrate', (int) ($videoKbps * 1.5) . 'k', '-bufsize', ($videoKbps * 2) . 'k',
                ...$audio,
                '-movflags', '+faststart',
                $dir . $name . '.mp4',
            ], $probe['duration'], $progress->setPhasePercentage(...));

            $this->storage->addRendition($media, $name . '-frame.png', 'image/png', $width, $height);
            $this->storage->addRendition($media, $name . '.mp4', 'video/mp4', $width, $height, (string) $videoKbps);
            $progress->endPhase();
        }
    }
}
