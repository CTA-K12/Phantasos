<?php

namespace App\Controller\Admin;

use App\Entity\Media;
use App\Enum\MediaStatus;
use App\Enum\MediaType;
use App\Repository\ApiClientRepository;
use App\Repository\MediaRepository;
use App\Search\MediaSearch;
use App\Service\AuditLogger;
use App\Service\MediaFileResponder;
use App\Service\MediaStorage;
use App\Service\Processing\Reprocessor;
use App\Util\LegacyId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin media browser. Media can hold ePHI (DocGuard, later ORMed), so opening a media
 * item and opening any of its files are both audited, and files are served with
 * no-store caching. Admin access bypasses API ownership by design; the audit trail is
 * the control.
 */
#[Route('/admin/media')]
#[IsGranted('ROLE_ADMIN')]
class MediaController extends AbstractController
{
    /** Shown in the browser; everything else downloads. */
    private const INLINE_TYPES = ['image/', 'video/', 'audio/', 'application/pdf'];

    public function __construct(
        private readonly MediaRepository $mediaRepository,
        private readonly ApiClientRepository $clients,
        private readonly MediaStorage $storage,
        private readonly MediaFileResponder $responder,
        private readonly Reprocessor $reprocessor,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_media_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search  = MediaSearch::fromRequest($request);
        $results = $this->mediaRepository->search($search);
        $total   = count($results);

        // A full 24-hex ID that matches exactly one item: go straight to it.
        if (strlen($search->id) === 24 && $total === 1 && $search->page === 1) {
            foreach ($results as $only) {
                return $this->redirectToRoute('admin_media_view', ['id' => $only->getId()]);
            }
        }

        return $this->render('admin/media/index.html.twig', [
            'search'     => $search,
            'results'    => $results,
            'total'      => $total,
            'totalPages' => max(1, (int) ceil($total / MediaSearch::PER_PAGE)),
            'clients'    => $this->clients->findAllOrdered(),
            'types'      => MediaType::cases(),
            'statuses'   => MediaStatus::cases(),
        ]);
    }

    #[Route('/{id}', name: 'admin_media_view', methods: ['GET'], requirements: ['id' => LegacyId::PATTERN])]
    public function view(string $id): Response
    {
        $media = $this->find($id);
        $this->auditLogger->log('media.admin_viewed', 'Media', $media->getId(), ['client' => $media->getClient()->getName()]);

        $sizes = [];
        foreach ($media->getFiles() as $file) {
            $sizes[$file->getId()] = $this->storage->fileSize($file);
        }

        $response = $this->render('admin/media/view.html.twig', [
            'media'    => $media,
            'sizes'    => $sizes,
            'canRetry' => $media->getStatus() !== MediaStatus::Ready && $this->reprocessor->canRetry($media),
            'inline'   => self::INLINE_TYPES,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/{id}/files/{fileId}', name: 'admin_media_file', methods: ['GET'], requirements: ['id' => LegacyId::PATTERN, 'fileId' => LegacyId::PATTERN])]
    public function openFile(string $id, string $fileId, Request $request): Response
    {
        $media = $this->find($id);
        $file  = null;
        foreach ($media->getFiles() as $candidate) {
            if ($candidate->getId() === $fileId) {
                $file = $candidate;
            }
        }
        if ($file === null || $this->storage->fileSize($file) === null) {
            throw $this->createNotFoundException();
        }

        $download = $request->query->getBoolean('download') || !self::isInline($file->getContentType());
        $this->auditLogger->log('media.admin_file_opened', 'MediaFile', $file->getId(), [
            'client'   => $media->getClient()->getName(),
            'media_id' => $media->getId(),
            'file'     => $file->getFileName(),
            'download' => $download,
        ]);

        $response = $this->responder->respond($file);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            $download ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            $media->getId() . '-' . $file->getFileName(),
        ));
        $response->headers->set('Cache-Control', 'no-store, private');
        // Uploaded content must never run as part of this site. PDFs are exempt: Chrome's
        // viewer refuses to render under `sandbox`, and PDFs are inline-only, never HTML.
        if ($file->getContentType() !== 'application/pdf') {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private static function isInline(string $contentType): bool
    {
        foreach (self::INLINE_TYPES as $prefix) {
            if (str_starts_with($contentType, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function find(string $id): Media
    {
        return $this->mediaRepository->find($id) ?? throw $this->createNotFoundException();
    }
}
