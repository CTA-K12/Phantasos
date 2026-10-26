<?php

namespace App\Service\Processing;

use App\Entity\Media;
use App\Enum\MediaStatus;
use App\Message\ProcessMedia;
use App\Repository\MediaRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/** Puts uploaded media back on the processing queue (the legacy `reprocess` command). */
class Reprocessor
{
    public function __construct(
        private readonly MediaRepository $mediaRepository,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function canRetry(Media $media): bool
    {
        return $media->isUploaded() && $media->getOriginalFile() !== null;
    }

    public function retry(Media $media): bool
    {
        if (!$this->canRetry($media)) {
            return false;
        }
        $media->setStatus(MediaStatus::Queued)->setProcessingPercentage(0.0)->setReady(false);
        $this->mediaRepository->flush();
        $this->bus->dispatch(new ProcessMedia($media->getId()));
        return true;
    }
}
