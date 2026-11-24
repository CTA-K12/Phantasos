<?php

namespace App\Tests\Functional\Api;

use App\Entity\ApiClient;
use App\Message\ProcessMedia;
use App\MessageHandler\ProcessMediaHandler;
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Image formats added 2026-11-22 (GIF, WebP, AVIF, HEIC, TIFF) and photo orientation, through
 * the API: upload, worker, then the colours of the medium rendition.
 */
class ImageFormatsTest extends WebTestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/images/';

    private const COLOURS = [
        'red'    => [255, 0, 0],
        'blue'   => [0, 0, 255],
        'green'  => [0, 255, 0],
        'yellow' => [255, 255, 0],
    ];

    private KernelBrowser $browser;
    private string $key;
    private string $tmp;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot();
        $em        = static::getContainer()->get(EntityManagerInterface::class);
        $this->key = bin2hex(random_bytes(16));
        $em->persist(new ApiClient('ORMed', $this->key));
        $em->flush();
        $this->tmp = sys_get_temp_dir() . '/phantasos-images-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->tmp);
        $fs->remove(static::getContainer()->get(MediaStorage::class)->getMediaRoot());
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> format => [writer, extension] */
    public static function gdFormats(): iterable
    {
        yield 'gif'  => ['imagegif', 'gif'];
        yield 'webp' => ['imagewebp', 'webp'];
        yield 'avif' => ['imageavif', 'avif'];
    }

    #[DataProvider('gdFormats')]
    public function testFormatsGdDecodes(string $writer, string $extension): void
    {
        $path = $this->tmp . '/halves.' . $extension;
        $writer($this->halves(400, 200), $path);

        $medium = $this->process($path, 'image/' . $extension);
        self::assertSame(['red', 'blue'], [$this->colourAt($medium, 160, 240), $this->colourAt($medium, 480, 240)]);
    }

    public function testTiffFromAScanner(): void
    {
        $medium = $this->process(self::FIXTURES . 'halves.tiff', 'image/tiff');
        self::assertSame(['red', 'blue'], [$this->colourAt($medium, 160, 240), $this->colourAt($medium, 480, 240)]);
    }

    public function testIphoneHeicIsReassembledAndTurnedUpright(): void
    {
        // Stored as a 1200×600 tile grid (left red, right blue) with a quarter-turn flag, as
        // iPhones do; upright it is red on top, blue below.
        $medium = $this->process(self::FIXTURES . 'rotated-grid.heic', 'image/heic');
        self::assertSame(['red', 'blue'], [$this->colourAt($medium, 320, 120), $this->colourAt($medium, 320, 360)]);
    }

    public function testGifTransparencyIsKept(): void
    {
        $image = imagecreate(100, 100);
        self::assertNotFalse($image);
        $clear = (int) imagecolorallocate($image, 255, 255, 255);
        imagecolortransparent($image, $clear);
        imagefilledrectangle($image, 30, 30, 69, 69, (int) imagecolorallocate($image, 255, 0, 0));
        imagegif($image, $path = $this->tmp . '/logo.gif');

        $medium = $this->process($path, 'image/gif');
        self::assertSame(127, (imagecolorat($medium, 5, 5) >> 24) & 0x7F, 'corner stays transparent');
        self::assertSame('red', $this->colourAt($medium, 320, 240));
    }

    /**
     * Quadrants as stored: red | blue over green | yellow. Each EXIF orientation must come out
     * as the photo was taken, at the advertised rendition size.
     *
     * @return iterable<string, array{int, list<string>}> orientation => [top-left, top-right, bottom-left, bottom-right]
     */
    public static function orientations(): iterable
    {
        yield '1 upright'              => [1, ['red', 'blue', 'green', 'yellow']];
        yield '2 mirrored'             => [2, ['blue', 'red', 'yellow', 'green']];
        yield '3 upside down'          => [3, ['yellow', 'green', 'blue', 'red']];
        yield '4 mirrored vertically'  => [4, ['green', 'yellow', 'red', 'blue']];
        yield '5 transposed'           => [5, ['red', 'green', 'blue', 'yellow']];
        yield '6 turned clockwise'     => [6, ['green', 'red', 'yellow', 'blue']];
        yield '7 transversed'          => [7, ['yellow', 'blue', 'green', 'red']];
        yield '8 turned anticlockwise' => [8, ['blue', 'yellow', 'red', 'green']];
    }

    /** @param list<string> $expected */
    #[DataProvider('orientations')]
    public function testJpegOrientation(int $orientation, array $expected): void
    {
        $image = imagecreatetruecolor(400, 300);
        self::assertNotFalse($image);
        foreach ([[0, 0, 'red'], [200, 0, 'blue'], [0, 150, 'green'], [200, 150, 'yellow']] as [$x, $y, $name]) {
            imagefilledrectangle($image, $x, $y, $x + 199, $y + 149, (int) imagecolorallocate($image, ...self::COLOURS[$name]));
        }
        ob_start();
        imagejpeg($image, null, 95);
        $path = $this->tmp . '/photo.jpg';
        file_put_contents($path, self::withExifOrientation((string) ob_get_clean(), $orientation));

        $medium = $this->process($path, 'image/jpeg');
        self::assertSame($expected, [
            $this->colourAt($medium, 160, 120), $this->colourAt($medium, 480, 120),
            $this->colourAt($medium, 160, 360), $this->colourAt($medium, 480, 360),
        ]);
    }

    /** Uploads through the API, runs the worker, and returns the medium rendition (640×480). */
    private function process(string $path, string $expectedMime): \GdImage
    {
        $this->browser->request('POST', '/api/requestUploadTicket', ['apikey' => $this->key]);
        $id = json_decode((string) $this->browser->getResponse()->getContent(), true)['media_id'];

        $upload = new UploadedFile($this->copy($path), basename($path), null, null, true);
        self::assertSame($expectedMime, $upload->getMimeType());
        $this->browser->request('POST', '/api/uploadMedia/' . $id, [], ['file_data' => $upload]);
        self::assertSame('Upload accepted', $this->browser->getResponse()->getContent());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $handler   = static::getContainer()->get(ProcessMediaHandler::class);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(ProcessMedia::class, $message);
            $handler($message);
        }

        $this->browser->request('GET', '/api/mediaInfo', ['media_id' => $id, 'apikey' => $this->key]);
        $info = json_decode((string) $this->browser->getResponse()->getContent(), true);
        self::assertSame(['image', 'Ready', true], [$info['type'], $info['status'], $info['ready']]);
        self::assertSame(['original', 'thumbnail.png', 'small.png', 'medium.png', 'large.png'], array_map(
            static fn (array $f): string => str_starts_with($f['file_name'], 'original.') ? 'original' : $f['file_name'],
            $info['files'],
        ));

        $this->browser->request('GET', '/api/requestMedia', ['media_file_id' => $info['files'][3]['media_file_id'], 'apikey' => $this->key]);
        $medium = imagecreatefromstring((string) $this->browser->getInternalResponse()->getContent());
        self::assertInstanceOf(\GdImage::class, $medium);
        self::assertSame([640, 480], [imagesx($medium), imagesy($medium)]);
        return $medium;
    }

    private function copy(string $path): string
    {
        $copy = $this->tmp . '/upload-' . bin2hex(random_bytes(4));
        copy($path, $copy);
        return $copy;
    }

    private function halves(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 255));
        return $image;
    }

    /** The nearest named colour (lossy formats shift colours slightly). */
    private function colourAt(\GdImage $image, int $x, int $y): string
    {
        $rgb  = imagecolorat($image, $x, $y);
        $best = '';
        $min  = PHP_INT_MAX;
        foreach (self::COLOURS as $name => [$r, $g, $b]) {
            $distance = (($rgb >> 16 & 0xFF) - $r) ** 2 + (($rgb >> 8 & 0xFF) - $g) ** 2 + (($rgb & 0xFF) - $b) ** 2;
            if ($distance < $min) {
                [$best, $min] = [$name, $distance];
            }
        }
        return $best;
    }

    /** Inserts a minimal EXIF block holding only the orientation tag, as cameras write it. */
    private static function withExifOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "MM\x00\x2A" . pack('N', 8) . pack('n', 1) . pack('nnN', 0x0112, 3, 1) . pack('n', $orientation) . "\x00\x00" . pack('N', 0);
        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', 8 + strlen($tiff)) . "Exif\x00\x00" . $tiff . substr($jpeg, 2);
    }
}
