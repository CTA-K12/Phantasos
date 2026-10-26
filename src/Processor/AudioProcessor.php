<?php

namespace App\Processor;

use App\Entity\Media;
use App\Enum\MediaType;
use App\Service\MediaStorage;
use App\Service\Processing\Ffmpeg;
use App\Service\Processing\ProgressReporter;

/**
 * MP3 at the legacy "low"/"high" bitrates. The legacy Vorbis (.oga) copies are no
 * longer produced: every current browser plays MP3, and consumers select files by name.
 */
class AudioProcessor implements ProcessorInterface
{
    /** name => kbit/s, in legacy order. */
    public const RENDITIONS = ['low' => 64, 'high' => 192];

    public function __construct(
        private readonly Ffmpeg $ffmpeg,
        private readonly MediaStorage $storage,
    ) {
    }

    public function getMediaType(): MediaType
    {
        return MediaType::Audio;
    }

    public function getSupportedMimeTypes(): array
    {
        return [
            'audio/mp4', 'audio/x-m4a',
            'audio/mpeg', 'audio/mpeg3', 'audio/x-mpeg-3', 'audio/x-mpeg',
            'application/ogg', 'audio/ogg', 'audio/vorbis',
            'audio/flac', 'audio/x-flac',
            'audio/vnd.rn-realaudio',
            'audio/vnd.wave', 'audio/wav', 'audio/x-wav',
            'audio/webm',
        ];
    }

    public function process(Media $media, ProgressReporter $progress): void
    {
        $original = $media->getOriginalFile() ?? throw new \RuntimeException('Missing original file.');
        $input    = $this->storage->absolutePath($original);
        $dir      = $this->storage->mediaDirectory($media);
        $duration = $this->ffmpeg->probe($input)['duration'];

        $progress->setPhases(count(self::RENDITIONS));
        foreach (self::RENDITIONS as $name => $kbps) {
            $progress->startPhase();
            $this->ffmpeg->encode($input, [
                '-vn', '-map', '0:a:0', '-ac', '2', '-c:a', 'libmp3lame', '-b:a', $kbps . 'k',
                $dir . $name . '.mp3',
            ], $duration, $progress->setPhasePercentage(...));
            $this->storage->addRendition($media, $name . '.mp3', 'audio/mpeg', null, null, (string) $kbps);
            $progress->endPhase();
        }
    }
}
