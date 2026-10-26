<?php

namespace App\Processor;

use App\Entity\Media;
use App\Enum\MediaType;
use App\Service\Processing\ProgressReporter;

/**
 * Generates renditions for one media type. Runs in the Messenger worker.
 *
 * Implementations write files into the media's directory and register each one via
 * MediaStorage::addRendition(); the handler flushes, marks ready and sends callbacks.
 */
interface ProcessorInterface
{
    public function getMediaType(): MediaType;

    /** @return list<string> MIME types (as sniffed from the upload) this processor accepts. */
    public function getSupportedMimeTypes(): array;

    public function process(Media $media, ProgressReporter $progress): void;
}
