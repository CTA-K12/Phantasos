<?php

namespace App\Tests\Unit\Service;

use App\Entity\ApiClient;
use App\Entity\Media;
use App\Entity\MediaFile;
use App\Service\MediaFileResponder;
use App\Service\MediaStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaFileResponderTest extends TestCase
{
    private function file(): MediaFile
    {
        $media = new Media(new ApiClient('Events', 'k'), new \DateTimeImmutable(), '6ac7ded7ae2adc02653a35a5');
        return new MediaFile($media, 'original.pdf', 'application/pdf');
    }

    public function testDelegatesToNginxWhenPrefixConfigured(): void
    {
        $responder = new MediaFileResponder(new MediaStorage('/media/phantasos'), '/_media/');

        $response = $responder->respond($this->file());

        self::assertSame('/_media/6ac7d/ed7ae/2adc0/6ac7ded7ae2adc02653a35a5/original.pdf', $response->headers->get('X-Accel-Redirect'));
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('', $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    public function testStreamsThroughPhpWithoutPrefix(): void
    {
        $root = sys_get_temp_dir() . '/phantasos-' . bin2hex(random_bytes(4));
        $file = $this->file();
        @mkdir($root . '/' . dirname($file->getStoragePath()), 0o775, true);
        file_put_contents($root . '/' . $file->getStoragePath(), '%PDF');

        try {
            $response = (new MediaFileResponder(new MediaStorage($root)))->respond($file);
            self::assertInstanceOf(BinaryFileResponse::class, $response);
            self::assertNull($response->headers->get('X-Accel-Redirect'));
        } finally {
            unlink($root . '/' . $file->getStoragePath());
        }
    }
}
