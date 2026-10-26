<?php

namespace App\Controller\Admin;

use App\Entity\Media;
use App\Repository\MediaRepository;
use App\Service\AuditLogger;
use App\Service\Processing\Reprocessor;
use App\Service\QueueStats;
use App\Util\LegacyId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Media that did not finish processing: "Failed to process", or stuck in the queue
 * long after any normal run (worker down, message lost). Retry puts them back on the
 * queue — the replacement for the legacy `reprocess` console command.
 */
#[Route('/admin/processing')]
#[IsGranted('ROLE_ADMIN')]
class ProcessingController extends AbstractController
{
    public function __construct(
        private readonly MediaRepository $mediaRepository,
        private readonly Reprocessor $reprocessor,
        private readonly QueueStats $queueStats,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_processing_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/processing/index.html.twig', [
            'failed' => $this->mediaRepository->findFailed(),
            'stuck'  => $this->mediaRepository->findStuck(),
            'queue'  => $this->queueStats->processing(),
            'stuckAfter' => ltrim(MediaRepository::STUCK_AFTER, '-'),
        ]);
    }

    #[Route('/{id}/retry', name: 'admin_processing_retry', methods: ['POST'], requirements: ['id' => LegacyId::PATTERN])]
    public function retry(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('processing-retry', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('admin_processing_index');
        }
        $media = $this->mediaRepository->find($id);
        if (!$media instanceof Media) {
            throw $this->createNotFoundException();
        }

        if ($this->retryOne($media)) {
            $this->addFlash('success', sprintf('Media %s is queued for processing again.', $id));
        } else {
            $this->addFlash('error', sprintf('Media %s has no original file to process.', $id));
        }

        return $this->redirectToRoute('admin_processing_index');
    }

    #[Route('/retry-all', name: 'admin_processing_retry_all', methods: ['POST'])]
    public function retryAll(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('processing-retry-all', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('admin_processing_index');
        }

        $queued = 0;
        foreach ([...$this->mediaRepository->findFailed(), ...$this->mediaRepository->findStuck()] as $media) {
            $queued += $this->retryOne($media) ? 1 : 0;
        }
        $this->addFlash('success', sprintf('%d media queued for processing again.', $queued));

        return $this->redirectToRoute('admin_processing_index');
    }

    private function retryOne(Media $media): bool
    {
        $previous = $media->getStatus()->value;
        if (!$this->reprocessor->retry($media)) {
            return false;
        }
        $this->auditLogger->log('media.reprocessed', 'Media', $media->getId(), [
            'client'          => $media->getClient()->getName(),
            'previous_status' => $previous,
        ]);
        return true;
    }
}
