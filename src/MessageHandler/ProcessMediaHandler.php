<?php

namespace App\MessageHandler;

use App\Entity\Media;
use App\Enum\MediaStatus;
use App\Message\ProcessMedia;
use App\Repository\MediaRepository;
use App\Service\CallbackNotifier;
use App\Service\Processing\ProcessorRegistry;
use App\Service\Processing\ProgressReporter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Worker side of an upload: run the type's processor, mark the media ready and fire
 * the consumer's callback. A processor exception marks the media "Failed to process"
 * and is rethrown so Messenger retries it (then parks it on the failed transport).
 */
#[AsMessageHandler]
class ProcessMediaHandler
{
    public function __construct(
        private readonly MediaRepository $mediaRepository,
        private readonly ProcessorRegistry $processors,
        private readonly CallbackNotifier $callbackNotifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessMedia $message): void
    {
        $media = $this->mediaRepository->find($message->mediaId);
        if (!$media instanceof Media) {
            throw new UnrecoverableMessageHandlingException(sprintf('Media %s does not exist.', $message->mediaId));
        }
        if ($media->isReady()) {
            return; // Redelivered after a completed run.
        }

        $type      = $media->getMediaType();
        $processor = $type !== null ? $this->processors->forType($type) : null;
        if ($processor === null || $media->getOriginalFile() === null) {
            $media->setStatus(MediaStatus::Failed);
            $this->mediaRepository->flush();
            throw new UnrecoverableMessageHandlingException(sprintf('Media %s has no processable original.', $media->getId()));
        }

        // A retry after a partial run must not duplicate renditions; files on disk are overwritten.
        foreach ($media->getFiles() as $file) {
            if (!$file->isOriginal()) {
                $media->getFiles()->removeElement($file);
            }
        }
        $this->mediaRepository->flush();

        try {
            $processor->process($media, new ProgressReporter($media, $this->mediaRepository));
        } catch (\Throwable $e) {
            $this->logger->error('Processing failed', ['media_id' => $media->getId(), 'error' => $e->getMessage()]);
            $media->setStatus(MediaStatus::Failed);
            $this->mediaRepository->flush();
            throw $e;
        }

        $media->setStatus(MediaStatus::Ready)->setProcessingPercentage(100.0)->setReady(true);
        $this->mediaRepository->flush();

        $callback = $media->getCallback();
        if ($callback !== null && $callback !== '') {
            $media->setCallbackSent($this->callbackNotifier->notifyReady($callback, $media->getId()));
            $this->mediaRepository->flush();
        }
    }
}
