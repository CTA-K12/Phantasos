<?php

namespace App\Service;

use App\Entity\MediaFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the response that delivers a stored file.
 *
 * With an accel prefix (prod, behind nginx) PHP only authorises the request and hands
 * the transfer to nginx via X-Accel-Redirect, so large videos and range requests never
 * occupy a PHP-FPM worker. Without one (dev, tests) PHP streams the file itself.
 */
class MediaFileResponder
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly string $accelPrefix = '',
    ) {
    }

    public function respond(MediaFile $file): Response
    {
        if ($this->accelPrefix !== '') {
            $response = new Response();
            $response->headers->set('X-Accel-Redirect', rtrim($this->accelPrefix, '/') . '/' . $file->getStoragePath());
        } else {
            $response = new BinaryFileResponse($this->storage->absolutePath($file), 200, [], false, null, false, true);
        }
        $response->headers->set('Content-Type', $file->getContentType());
        $response->setPrivate();

        return $response;
    }
}
