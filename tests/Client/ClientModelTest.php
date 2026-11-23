<?php

namespace App\Tests\Client;

use App\Phantasos\FileStream;
use App\Phantasos\LocalMediaStore;
use App\Phantasos\MediaInfo;
use App\Phantasos\MediaNotFoundException;
use App\Phantasos\ProcessingStatus;
use App\Phantasos\UploadRejectedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** The client's response model against recorded legacy responses, FileStream headers, and the local fake. */
class ClientModelTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phantasos-model-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    /** @return iterable<string, array{string}> */
    public static function mediaInfoFixtures(): iterable
    {
        foreach (glob(__DIR__ . '/../Contract/fixtures/legacy/mediaInfo_*_{image,pdf,audio,video,ticket}.json', GLOB_BRACE) ?: [] as $path) {
            yield basename($path, '.json') => [$path];
        }
    }

    #[DataProvider('mediaInfoFixtures')]
    public function testParsesRecordedMediaInfo(string $fixture): void
    {
        $recorded = json_decode((string) file_get_contents($fixture), true);
        $json     = $recorded['response']['json'];
        $info     = MediaInfo::fromArray('6a615189ae2adc1459007dac', $json);

        self::assertSame($json['ready'], $info->ready);
        self::assertSame($json['status'], $info->status->value);
        self::assertCount(count($json['files']), $info->files);
        if ($info->ready) {
            self::assertSame($json['files'][0]['media_file_id'], $info->original()?->id);
        } else {
            self::assertNull($info->original());
        }
        foreach ($info->files as $i => $file) {
            self::assertSame($json['files'][$i]['file_name'], $file->fileName);
            self::assertSame($json['files'][$i]['size']['width'] ?? null, $file->width);
        }
    }

    public function testVideoBitratesAndFrames(): void
    {
        $recorded = json_decode((string) file_get_contents(__DIR__ . '/../Contract/fixtures/legacy/mediaInfo_Training_video.json'), true);
        $info     = MediaInfo::fromArray('6a615189ae2adc1459007dac', $recorded['response']['json']);

        self::assertSame('video', $info->type);
        self::assertSame('750', $info->file('small.mp4')?->bitRate);
        self::assertSame(640, $info->file('small-frame.png')?->width);
    }

    public function testResponseHeadersForAViewableRange(): void
    {
        $stream   = new FileStream(206, 'video/mp4', 4, 'bytes 0-3/100', ['ab', 'cd']);
        $response = $stream->toResponse('clip.mp4');

        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes 0-3/100', $response->headers->get('Content-Range'));
        self::assertSame('4', $response->headers->get('Content-Length'));
        self::assertSame('bytes', $response->headers->get('Accept-Ranges'));
        self::assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));

        ob_start();
        $response->sendContent();
        self::assertSame('abcd', ob_get_clean());
    }

    public function testHtmlIsAlwaysDownloadedAndPdfsAreNotSandboxed(): void
    {
        $html = (new FileStream(200, 'text/html', null, null, ['<script>']))->toResponse('page.html');
        self::assertStringStartsWith('attachment;', (string) $html->headers->get('Content-Disposition'));

        $pdf = (new FileStream(200, 'application/pdf', null, null, []))->toResponse('Résumé.pdf');
        self::assertStringStartsWith('inline;', (string) $pdf->headers->get('Content-Disposition'));
        self::assertStringContainsString("filename*=utf-8''R%C3%A9sum%C3%A9.pdf", (string) $pdf->headers->get('Content-Disposition'));
        self::assertFalse($pdf->headers->has('Content-Security-Policy'));
    }

    public function testAStreamCanOnlyBeReadOnce(): void
    {
        $stream = new FileStream(200, 'image/png', 1, null, ['x']);
        $stream->contents();
        $this->expectException(\LogicException::class);
        $stream->contents();
    }

    public function testLocalStoreBehavesLikePhantasos(): void
    {
        $store = new LocalMediaStore($this->tmp . '/store');
        $pdf   = $this->tmp . '/upload.tmp';
        file_put_contents($pdf, "%PDF-1.4\n" . str_repeat('x', 1000) . "\n%%EOF\n");

        $id   = $store->upload($pdf, 'Report.PDF');
        $info = $store->info($id);
        self::assertTrue($info->ready);
        self::assertSame(ProcessingStatus::Ready, $info->status);
        self::assertSame('pdf', $info->type);
        self::assertSame('original.pdf', $info->original()?->fileName);
        self::assertSame('application/pdf', $info->original()?->contentType);

        $fileId = (string) $info->original()?->id;
        self::assertSame((string) file_get_contents($pdf), $store->fetch($fileId)->contents());

        $range = $store->fetch($fileId, 'bytes=0-3');
        self::assertSame([206, 'bytes 0-3/' . filesize($pdf), '%PDF'], [$range->status, $range->contentRange, $range->contents()]);
        self::assertSame("%%EOF\n", $store->fetch($fileId, 'bytes=-6')->contents());

        $saved = $this->tmp . '/saved.pdf';
        $store->fetch($fileId)->saveTo($saved);
        self::assertFileEquals($pdf, $saved);
    }

    public function testLocalStoreRejectsWhatPhantasosRejects(): void
    {
        $path = $this->tmp . '/notes.txt';
        file_put_contents($path, "plain text\n");

        $this->expectException(UploadRejectedException::class);
        $this->expectExceptionMessage('Filetype "text/plain" is not supported.');
        (new LocalMediaStore($this->tmp . '/store'))->upload($path);
    }

    public function testLocalStoreUnknownIds(): void
    {
        $store = new LocalMediaStore($this->tmp . '/store');
        foreach (['000000000000000000000000', '../media'] as $id) {
            try {
                $store->info($id);
                self::fail('expected MediaNotFoundException');
            } catch (MediaNotFoundException) {
            }
        }
        $this->expectException(MediaNotFoundException::class);
        $store->fetch('000000000000000000000000');
    }
}
