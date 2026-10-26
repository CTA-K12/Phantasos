<?php

namespace App\Tests\Functional\Api;

use App\Entity\ApiClient;
use App\Entity\Media;
use App\Enum\MediaStatus;
use App\Message\ProcessMedia;
use App\MessageHandler\ProcessMediaHandler;
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Full path: ticket → browser upload → worker → mediaInfo/requestMedia. */
class UploadAndProcessTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $em;
    private string $key;
    private string $tmp;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot(); // keep the in-memory queue between requests
        $this->em      = static::getContainer()->get(EntityManagerInterface::class);
        $this->key     = bin2hex(random_bytes(16));
        $this->em->persist(new ApiClient('Training', $this->key));
        $this->em->flush();
        $this->tmp = sys_get_temp_dir() . '/phantasos-upload-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->tmp);
        $fs->remove(static::getContainer()->get(MediaStorage::class)->getMediaRoot());
        parent::tearDown();
    }

    private function ticket(): string
    {
        $this->browser->request('POST', '/api/requestUploadTicket', ['apikey' => $this->key]);
        $body = json_decode((string) $this->browser->getResponse()->getContent(), true);
        self::assertIsArray($body);
        return $body['media_id'];
    }

    private function upload(string $ticket, string $path, string $clientName): void
    {
        $file = new UploadedFile($path, $clientName, null, null, true);
        $this->browser->request('POST', '/api/uploadMedia/' . $ticket, [], ['file_data' => $file]);
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(800, 600);
        self::assertNotFalse($img);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 122, 90, 248));
        $path = $this->tmp . '/photo.png';
        imagepng($img, $path);
        return $path;
    }

    private function runWorker(): void
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $handler   = static::getContainer()->get(ProcessMediaHandler::class);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(ProcessMedia::class, $message);
            $handler($message);
        }
    }

    private function mediaInfo(string $id): array
    {
        $this->browser->request('GET', '/api/mediaInfo', ['media_id' => $id, 'apikey' => $this->key]);
        $info = json_decode((string) $this->browser->getResponse()->getContent(), true);
        self::assertIsArray($info);
        return $info;
    }

    public function testImageUploadProducesLegacyRenditions(): void
    {
        $id = $this->ticket();
        $this->upload($id, $this->png(), 'photo.png');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Upload accepted', $this->browser->getResponse()->getContent());
        self::assertResponseHeaderSame('access-control-allow-origin', '*');

        // Queued, not yet processed. (Checked in the DB: another request would reset the in-memory queue.)
        $media = $this->em->find(Media::class, $id);
        self::assertInstanceOf(Media::class, $media);
        self::assertSame([true, false, MediaStatus::Queued], [$media->isUploaded(), $media->isReady(), $media->getStatus()]);

        $this->runWorker();

        $info = $this->mediaInfo($id);
        self::assertTrue($info['ready']);
        self::assertSame('Ready', $info['status']);
        self::assertSame(100, $info['processing_percentage']);
        self::assertTrue($info['original_exists']);

        // Same file list, order and sizes as legacy (fixture mediaInfo_Training_image).
        $legacy = json_decode((string) file_get_contents(__DIR__ . '/../../Contract/fixtures/legacy/mediaInfo_Training_image.json'), true);
        $shape  = static fn (array $files): array => array_map(
            static fn (array $f): array => [$f['file_name'], $f['content_type'], $f['size']],
            $files,
        );
        $expected = $shape(json_decode($legacy['response']['raw'], true)['files']); // raw keeps legacy key order
        $expected[0] = ['original.png', 'image/png', []]; // legacy sample was a JPEG
        self::assertSame($expected, $shape($info['files']));

        // Renditions are real PNGs at the advertised size, and downloadable.
        $small = $info['files'][2];
        $this->browser->request('GET', '/api/requestMedia', ['media_file_id' => $small['media_file_id'], 'apikey' => $this->key]);
        self::assertResponseStatusCodeSame(200);
        $size = getimagesizefromstring((string) $this->browser->getInternalResponse()->getContent());
        self::assertIsArray($size);
        self::assertSame([320, 240], [$size[0], $size[1]]);
    }

    public function testPdfIsReadyWithOnlyTheOriginal(): void
    {
        $id   = $this->ticket();
        $path = $this->tmp . '/doc.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $this->upload($id, $path, 'doc.pdf');
        self::assertSame('Upload accepted', $this->browser->getResponse()->getContent());

        $this->runWorker();

        $info = $this->mediaInfo($id);
        self::assertSame('pdf', $info['type']);
        self::assertTrue($info['ready']);
        self::assertCount(1, $info['files']);
        self::assertSame(['original.pdf', 'application/pdf'], [$info['files'][0]['file_name'], $info['files'][0]['content_type']]);
    }

    public function testSecondUploadToSameTicketIsRejected(): void
    {
        $id = $this->ticket();
        $this->upload($id, $this->png(), 'photo.png');
        $this->upload($id, $this->png(), 'photo.png');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Upload ticket already used', $this->browser->getResponse()->getContent());
    }

    public function testUnsupportedTypeIsRejectedWithLegacyMessage(): void
    {
        $id   = $this->ticket();
        $path = $this->tmp . '/notes.txt';
        file_put_contents($path, "plain text\n");
        $this->upload($id, $path, 'notes.txt');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Filetype "text/plain" is not supported.', $this->browser->getResponse()->getContent());
        $media = $this->em->find(Media::class, $id);
        self::assertInstanceOf(Media::class, $media);
        self::assertFalse($media->isUploaded());
    }

    public function testMissingFileIsRejected(): void
    {
        $id = $this->ticket();
        $this->browser->request('POST', '/api/uploadMedia/' . $id);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('File was not given', $this->browser->getResponse()->getContent());
    }

    public function testCorruptImageMarksMediaFailed(): void
    {
        $id   = $this->ticket();
        $path = $this->tmp . '/broken.png';
        file_put_contents($path, substr((string) file_get_contents($this->png()), 0, 60)); // header intact, data truncated
        $this->upload($id, $path, 'broken.png');
        self::assertSame('Upload accepted', $this->browser->getResponse()->getContent());

        try {
            $this->runWorker();
            self::fail('Expected processing to fail');
        } catch (\RuntimeException) {
        }

        $this->em->clear();
        $media = $this->em->find(Media::class, $id);
        self::assertInstanceOf(Media::class, $media);
        self::assertSame(MediaStatus::Failed, $media->getStatus());
        self::assertFalse($media->isReady());
    }

    /** @param list<string> $args */
    private function ffmpegFixture(string $name, array $args): string
    {
        $ffmpeg = (new \Symfony\Component\Process\ExecutableFinder())->find('ffmpeg');
        if ($ffmpeg === null) {
            self::markTestSkipped('ffmpeg not installed');
        }
        $path = $this->tmp . '/' . $name;
        (new \Symfony\Component\Process\Process([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', ...$args, $path]))->mustRun();
        return $path;
    }

    /** @return list<array{string, string, array<string, mixed>}> */
    private static function fileShape(array $info): array
    {
        return array_map(static fn (array $f): array => [$f['file_name'], $f['content_type'], $f['size']], $info['files']);
    }

    public function testVideoProducesFramesAndMp4s(): void
    {
        $path = $this->ffmpegFixture('clip.mp4', [
            '-f', 'lavfi', '-i', 'testsrc=size=320x240:rate=15:duration=2',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest',
        ]);
        $id = $this->ticket();
        $this->upload($id, $path, 'clip.mp4');
        self::assertSame('Upload accepted', $this->browser->getResponse()->getContent());

        $this->runWorker();

        $info = $this->mediaInfo($id);
        self::assertSame(['video', true, 'Ready'], [$info['type'], $info['ready'], $info['status']]);
        self::assertSame([
            ['original.mp4', 'video/mp4', []],
            ['small-frame.png', 'image/png', ['width' => 640, 'height' => 360]],
            ['small.mp4', 'video/mp4', ['width' => 640, 'height' => 360, 'bitrate' => '750']],
            ['large-frame.png', 'image/png', ['width' => 1280, 'height' => 720]],
            ['large.mp4', 'video/mp4', ['width' => 1280, 'height' => 720, 'bitrate' => '2500']],
        ], self::fileShape($info));

        $media = $this->em->find(Media::class, $id);
        self::assertInstanceOf(Media::class, $media);
        $dir = static::getContainer()->get(MediaStorage::class)->mediaDirectory($media);
        $frame = getimagesize($dir . 'small-frame.png');
        self::assertIsArray($frame);
        self::assertSame([640, 360], [$frame[0], $frame[1]]);
        $probe = static::getContainer()->get(\App\Service\Processing\Ffmpeg::class)->probe($dir . 'small.mp4');
        self::assertTrue($probe['hasVideo'] && $probe['hasAudio']);
    }

    public function testAudioProducesMp3s(): void
    {
        $path = $this->ffmpegFixture('tone.mp3', ['-f', 'lavfi', '-i', 'sine=frequency=440:duration=2', '-c:a', 'libmp3lame']);
        $id = $this->ticket();
        $this->upload($id, $path, 'tone.mp3');
        self::assertSame('Upload accepted', $this->browser->getResponse()->getContent());

        $this->runWorker();

        $info = $this->mediaInfo($id);
        self::assertSame(['audio', true], [$info['type'], $info['ready']]);
        self::assertSame([
            ['original.mp3', 'audio/mpeg', []],
            ['low.mp3', 'audio/mpeg', ['bitrate' => '64']],
            ['high.mp3', 'audio/mpeg', ['bitrate' => '192']],
        ], self::fileShape($info));
    }

    public function testAudioOnlyWebmIsRetypedAsAudio(): void
    {
        $path = $this->ffmpegFixture('voice.webm', ['-f', 'lavfi', '-i', 'sine=frequency=440:duration=2', '-c:a', 'libopus']);
        $id = $this->ticket();
        $this->upload($id, $path, 'voice.webm');
        self::assertSame('Upload accepted', $this->browser->getResponse()->getContent());

        $this->runWorker();

        $info = $this->mediaInfo($id);
        self::assertSame('audio', $info['type']);
        self::assertSame(['original.webm', 'low.mp3', 'high.mp3'], array_column($info['files'], 'file_name'));
    }
}
