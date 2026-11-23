<?php

namespace App\Tests\Client;

use App\Entity\ApiClient;
use App\Message\ProcessMedia;
use App\MessageHandler\ProcessMediaHandler;
use App\Phantasos\AuthenticationFailedException;
use App\Phantasos\MediaNotFoundException;
use App\Phantasos\PhantasosClient;
use App\Phantasos\PhantasosException;
use App\Phantasos\ProcessingStatus;
use App\Phantasos\UploadRejectedException;
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The reference client (client/src) against this Phantasos app: each HTTP request the client
 * makes is replayed through the kernel, so the client is tested against the real API.
 */
class PhantasosClientTest extends WebTestCase
{
    private KernelBrowser $browser;
    private string $key;
    private string $tmp;
    /** @var list<array{method: string, url: string, headers: array<string, list<string>>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot(); // keep the in-memory queue between requests
        $em        = static::getContainer()->get(EntityManagerInterface::class);
        $this->key = bin2hex(random_bytes(16));
        $em->persist(new ApiClient('ORMed', $this->key));
        $em->flush();
        $this->tmp = sys_get_temp_dir() . '/phantasos-client-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->tmp);
        $fs->remove(static::getContainer()->get(MediaStorage::class)->getMediaRoot());
        parent::tearDown();
    }

    public function testUploadProcessInfoAndStream(): void
    {
        $client = $this->client();
        $id     = $client->upload($this->png(), 'photo.png');
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $id);

        // The key goes in the header of API calls only: never in a URL, never with the file.
        [$ticket, $upload] = $this->requests;
        self::assertSame(['X-Api-Key: ' . $this->key], $ticket['headers']['x-api-key'] ?? null);
        self::assertStringContainsString('/api/uploadMedia/' . $id, $upload['url']);
        self::assertArrayNotHasKey('x-api-key', $upload['headers']);

        $this->runWorker(); // before any other request: a request resets the in-memory queue

        $info = $client->info($id);
        self::assertTrue($info->ready);
        self::assertSame(ProcessingStatus::Ready, $info->status);
        self::assertSame('image', $info->type);
        self::assertSame(100.0, $info->processingPercentage);
        self::assertSame('original.png', $info->original()?->fileName);
        self::assertSame('original.png', $info->files[0]->fileName, 'original first');
        $small = $info->file('small.png');
        self::assertNotNull($small);
        self::assertSame([320, 240], [$small->width, $small->height]);

        $size = getimagesizefromstring($client->fetch($small->id)->contents());
        self::assertIsArray($size);
        self::assertSame([320, 240], [$size[0], $size[1]]);

        // A range request comes back as 206 with just those bytes.
        $original = $info->original();
        self::assertNotNull($original);
        $part = $client->fetch($original->id, 'bytes=0-99');
        self::assertSame(206, $part->status);
        self::assertSame(100, $part->contentLength);
        self::assertStringStartsWith('bytes 0-99/', (string) $part->contentRange);
        self::assertSame(100, strlen($part->contents()));

        foreach ($this->requests as $request) {
            self::assertStringNotContainsString($this->key, $request['url']);
        }
    }

    public function testInfoBeforeProcessingIsQueued(): void
    {
        $client = $this->client();
        $id     = $client->upload($this->png(), 'photo.png');

        $info = $client->info($id);
        self::assertFalse($info->ready);
        self::assertSame(ProcessingStatus::Queued, $info->status);
        self::assertSame([], $info->files);
        self::assertNull($info->original());
    }

    public function testUnsupportedFileIsRejectedWithTheServersReason(): void
    {
        $path = $this->tmp . '/notes.txt';
        file_put_contents($path, "just text\n");

        $this->expectException(UploadRejectedException::class);
        $this->expectExceptionMessage('Filetype "text/plain" is not supported.');
        $this->client()->upload($path, 'notes.txt');
    }

    public function testWrongKeyFailsAuthentication(): void
    {
        $this->expectException(AuthenticationFailedException::class);
        $this->client('not-the-key')->info('000000000000000000000000');
    }

    public function testAnotherAppsMediaIsNotFound(): void
    {
        $id = $this->client()->upload($this->png(), 'photo.png');

        $otherKey = bin2hex(random_bytes(16));
        $em       = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new ApiClient('ACCESS', $otherKey));
        $em->flush();

        $this->expectException(MediaNotFoundException::class);
        $this->client($otherKey)->info($id);
    }

    public function testMalformedIdsFailWithoutARequest(): void
    {
        try {
            $this->client()->fetch('../../etc/passwd');
            self::fail('expected MediaNotFoundException');
        } catch (MediaNotFoundException) {
        }
        self::assertSame([], $this->requests);
    }

    public function testFileIsNeverSentToAnotherHost(): void
    {
        $sent = 0;
        $http = new MockHttpClient(function () use (&$sent): MockResponse {
            ++$sent;
            return new MockResponse(json_encode([
                'upload_route' => 'https://elsewhere.example/api/uploadMedia/6a615189ae2adc1459007dac',
                'media_id'     => '6a615189ae2adc1459007dac',
                'expiration'   => '2026-11-22 12:00:00',
            ], JSON_THROW_ON_ERROR));
        });

        try {
            (new PhantasosClient($http, 'https://phantasos.apps.cascadetech.org', 'k'))->upload($this->png());
            self::fail('expected PhantasosException');
        } catch (PhantasosException $e) {
            self::assertStringContainsString('another host', $e->getMessage());
        }
        self::assertSame(1, $sent, 'only the ticket request');
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $options = [];
        $http    = new MockHttpClient(function (string $method, string $url, array $o) use (&$options): MockResponse {
            $options = $o;
            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://elsewhere.example/']]);
        });

        try {
            (new PhantasosClient($http, 'https://phantasos.apps.cascadetech.org', 'k'))->info('6a615189ae2adc1459007dac');
            self::fail('expected PhantasosException');
        } catch (PhantasosException $e) {
            self::assertStringContainsString('HTTP 302', $e->getMessage());
        }
        self::assertSame(0, $options['max_redirects'] ?? null);
    }

    public function testPlainHttpIsOnlyAllowedForLocalhost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PhantasosClient(new MockHttpClient(), 'http://phantasos.apps.cascadetech.org', 'k');
    }

    public function testKeyIsHiddenFromDumps(): void
    {
        self::assertStringNotContainsString($this->key, print_r($this->client(), true));
    }

    private function client(?string $key = null): PhantasosClient
    {
        return new PhantasosClient($this->kernelHttpClient(), 'http://localhost', $key ?? $this->key);
    }

    /** An HttpClient that serves each request from this app's kernel. */
    private function kernelHttpClient(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var array<string, list<string>> $headers */
            $headers          = $options['normalized_headers'];
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];

            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            $server = [];
            foreach ($headers as $name => $lines) {
                if ($name !== 'content-type' && $name !== 'content-length') {
                    $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = substr($lines[0], strlen($name) + 2);
                }
            }

            $parameters = $query;
            $files      = [];
            if ($method === 'POST') {
                $body        = self::readBody($options['body'] ?? '');
                $contentType = substr($headers['content-type'][0] ?? '', strlen('content-type: '));
                if (str_starts_with($contentType, 'multipart/form-data')) {
                    [$parameters, $files] = $this->parseMultipart($contentType, $body);
                } else {
                    parse_str($body, $parameters);
                }
            }

            $this->browser->request($method, (string) $parts['path'], $parameters, $files, $server);
            $response = $this->browser->getInternalResponse();

            return new MockResponse((string) $response->getContent(), [
                'http_code'        => $response->getStatusCode(),
                'response_headers' => $response->getHeaders(),
            ]);
        });
    }

    private static function readBody(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }
        $content = '';
        if ($body instanceof \Closure) {
            while ('' !== $chunk = $body(16384)) {
                $content .= $chunk;
            }
        }
        return $content;
    }

    /** @return array{0: array<string, string>, 1: array<string, UploadedFile>} */
    private function parseMultipart(string $contentType, string $body): array
    {
        self::assertMatchesRegularExpression('/boundary=/', $contentType);
        preg_match('/boundary="?([^";]+)"?/', $contentType, $m);
        $parameters = [];
        $files      = [];
        foreach (explode('--' . $m[1], $body) as $part) {
            if (!str_contains($part, "\r\n\r\n")) {
                continue;
            }
            [$head, $content] = explode("\r\n\r\n", ltrim($part, "\r\n"), 2);
            $content          = substr($content, 0, -2);
            preg_match('/name="([^"]+)"/', $head, $name);
            if (preg_match('/filename="([^"]*)"/', $head, $fileName)) {
                $path = $this->tmp . '/upload-' . bin2hex(random_bytes(4));
                file_put_contents($path, $content);
                $files[$name[1]] = new UploadedFile($path, $fileName[1], null, null, true);
            } else {
                $parameters[$name[1]] = $content;
            }
        }
        return [$parameters, $files];
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(800, 600);
        self::assertNotFalse($img);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 122, 90, 248));
        $path = $this->tmp . '/photo-' . bin2hex(random_bytes(4)) . '.png';
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
}
