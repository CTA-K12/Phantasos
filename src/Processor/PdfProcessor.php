<?php

namespace App\Processor;

use App\Entity\Media;
use App\Enum\MediaType;
use App\Service\Processing\ProgressReporter;

/** PDFs are served as uploaded; no renditions. */
class PdfProcessor implements ProcessorInterface
{
    public function getMediaType(): MediaType
    {
        return MediaType::Pdf;
    }

    public function getSupportedMimeTypes(): array
    {
        return ['application/pdf', 'application/x-pdf'];
    }

    public function process(Media $media, ProgressReporter $progress): void
    {
        $progress->startPhase();
        $progress->endPhase();
    }
}
