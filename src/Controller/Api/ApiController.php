<?php

namespace App\Controller\Api;

use App\Entity\ApiClient;
use App\Entity\Media;
use App\Message\ProcessMedia;
use App\Repository\MediaFileRepository;
use App\Repository\MediaRepository;
use App\Service\AuditLogger;
use App\Service\MediaFileResponder;
use App\Service\MediaInfoPresenter;
use App\Service\MediaStorage;
use App\Service\Processing\ProcessorRegistry;
use App\Util\LegacyId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The public Phantasos API. Paths, parameters, status codes and bodies match the
 * legacy system (see tests/Contract) so consumers switch over without changes.
 *
 * Deliberate differences from legacy, all security fixes:
 *  - every read is restricted to media owned by the calling key (legacy served any
 *    file to any key);
 *  - unknown or foreign IDs return 404 rather than 500;
 *  - the key may also be sent as an `X-Api-Key` header.
 */
#[Route('/api')]
class ApiController extends AbstractController
{
    private const TICKET_LIFETIME = '+2 hours';

    private const CORS_HEADERS = [
        'Access-Control-Allow-Credentials' => 'true',
        'Access-Control-Allow-Origin'      => '*',
        'Access-Control-Allow-Methods'     => 'POST, GET, PUT, OPTIONS',
        'Access-Control-Allow-Headers'     => 'Content-Type, X-Requested-With, X-PINGOTHER, X-File-Name, Cache-Control, Origin',
    ];

    public function __construct(
        private readonly MediaRepository $mediaRepository,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly MediaStorage $storage,
        private readonly MediaInfoPresenter $presenter,
        private readonly ProcessorRegistry $processors,
        private readonly MessageBusInterface $bus,
        private readonly AuditLogger $auditLogger,
        private readonly MediaFileResponder $responder,
    ) {
    }

    #[Route('/requestUploadTicket', name: 'api_request_upload_ticket', methods: ['POST'])]
    public function requestUploadTicket(Request $request, #[CurrentUser] ApiClient $client): JsonResponse
    {
        $media = new Media($client, new \DateTimeImmutable(self::TICKET_LIFETIME));
        $media->setTags(self::csv($request, 'tags'));
        $media->setSecurityTags(self::csv($request, 'security'));
        $media->setHideToOthers(
            $request->request->has('hide_to_others')
                ? filter_var($request->request->getString('hide_to_others'), FILTER_VALIDATE_BOOLEAN)
                : true
        );
        if ($request->request->has('callback_route')) {
            $media->setCallback($request->request->getString('callback_route'));
        }
        $this->mediaRepository->save($media, true);

        return new JsonResponse([
            'upload_route' => $this->generateUrl(
                'api_upload_media',
                ['ticketId' => $media->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            'media_id'     => $media->getId(),
            'expiration'   => $media->getUploadExpiration()->format('Y-m-d H:i:s'),
        ]);
    }

    /** Called from the end user's browser with the one-time ticket; no API key. */
    #[Route('/uploadMedia/{ticketId}', name: 'api_upload_media', methods: ['POST', 'OPTIONS'])]
    public function uploadMedia(Request $request, string $ticketId): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return self::cors(new Response('Ok'));
        }

        $media = LegacyId::isValid($ticketId) ? $this->mediaRepository->find($ticketId) : null;
        if (!$media instanceof Media) {
            return self::cors(new Response('Not a valid ticket', 400));
        }
        if ($media->isUploaded()) {
            return self::cors(new Response('Upload ticket already used', 400));
        }
        if (new \DateTimeImmutable() > $media->getUploadExpiration()) {
            return self::cors(new Response('Not a valid ticket', 400));
        }
        $upload = $request->files->get('file_data');
        if (!$upload instanceof UploadedFile || !$upload->isValid()) {
            return self::cors(new Response('File was not given', 400));
        }

        $mimeType = (string) $upload->getMimeType();
        $type     = $this->processors->mediaTypeForMime($mimeType);
        if ($type === null) {
            return self::cors(new Response(sprintf('Filetype "%s" is not supported.', $mimeType), 400));
        }

        $this->storage->storeOriginal($media, $upload, $mimeType);
        $media->setUploaded(true)->setMediaType($type);
        $this->mediaRepository->flush();

        $this->bus->dispatch(new ProcessMedia($media->getId()));

        return self::cors(new Response('Upload accepted'));
    }

    #[Route('/mediaInfo', name: 'api_media_info', methods: ['GET'])]
    public function mediaInfo(Request $request, #[CurrentUser] ApiClient $client): Response
    {
        if (!$request->query->has('media_id')) {
            return new Response('Field "media_id" is required', 400);
        }
        $mediaId = $request->query->getString('media_id');

        $media = LegacyId::isValid($mediaId) ? $this->mediaRepository->find($mediaId) : null;
        if (!$media instanceof Media || !$media->isOwnedBy($client)) {
            return new Response('Media was not found', 404);
        }

        return new JsonResponse($this->presenter->toArray($media));
    }

    #[Route('/requestMedia', name: 'api_request_media', methods: ['GET'])]
    public function requestMedia(Request $request, #[CurrentUser] ApiClient $client): Response
    {
        if (!$request->query->has('media_file_id')) {
            return new Response('Field "media_file_id" is required', 400);
        }
        $fileId = $request->query->getString('media_file_id');

        $file = LegacyId::isValid($fileId) ? $this->mediaFileRepository->find($fileId) : null;
        if ($file === null || !$file->getMedia()->isOwnedBy($client)) {
            return new Response('Media was not found', 404);
        }

        $path = $this->storage->absolutePath($file);
        if (!is_file($path)) {
            return new Response('Media was not found', 404);
        }

        $this->auditLogger->log('media.served', 'MediaFile', $file->getId(), [
            'client'   => $client->getName(),
            'media_id' => $file->getMedia()->getId(),
        ]);

        return $this->responder->respond($file);
    }

    /** @return list<string> */
    private static function csv(Request $request, string $field): array
    {
        if (!$request->request->has($field)) {
            return [];
        }
        return explode(',', $request->request->getString($field));
    }

    private static function cors(Response $response): Response
    {
        $response->headers->add(self::CORS_HEADERS);
        return $response;
    }
}
