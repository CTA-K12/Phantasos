<?php

namespace App\Tests\Contract;

use App\Entity\ApiClient;
use App\Entity\Media;
use App\Entity\MediaFile;
use App\Enum\MediaStatus;
use App\Enum\MediaType;
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Replays responses recorded from the legacy server (migration/capture_contract.py,
 * fixtures in tests/Contract/fixtures/legacy) against the new API.
 *
 * mediaInfo fixtures are rebuilt as entities from their own JSON and must come back
 * byte-identical. Cases where the new system deliberately differs (ownership on
 * requestMedia, 404 instead of 500) are asserted separately and say so.
 */
class LegacyApiContractTest extends WebTestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/legacy';

    private KernelBrowser $browser;
    private EntityManagerInterface $em;

    /** @var array<string, string> owner name => plaintext test key */
    private array $keys = [];

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->em      = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $root = static::getContainer()->get(MediaStorage::class)->getMediaRoot();
        if (is_dir($root)) {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($root);
        }
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private static function fixture(string $name): array
    {
        $data = json_decode((string) file_get_contents(self::FIXTURES . '/' . $name . '.json'), true);
        self::assertIsArray($data);
        return $data;
    }

    /** @return iterable<string, array{string}> */
    public static function mediaInfoFixtures(): iterable
    {
        foreach (glob(self::FIXTURES . '/mediaInfo_*.json') ?: [] as $path) {
            $name = basename($path, '.json');
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) && ($data['response']['status'] ?? null) === 200) {
                yield $name => [$name];
            }
        }
    }

    private function client(string $name): ApiClient
    {
        $existing = $this->em->getRepository(ApiClient::class)->findOneBy(['name' => $name]);
        if ($existing instanceof ApiClient) {
            return $existing;
        }
        $this->keys[$name] = bin2hex(random_bytes(16));
        $client = new ApiClient($name, $this->keys[$name]);
        $this->em->persist($client);
        $this->em->flush();
        return $client;
    }

    /** Builds a Media (and its files) from a legacy mediaInfo body. */
    private function mediaFromLegacy(string $mediaId, array $info): Media
    {
        $media = new Media($this->client($info['owner']), new \DateTimeImmutable('-1 day'), $mediaId);
        $media->setTags($info['tags'])
            ->setSecurityTags($info['security'])
            ->setUploaded($info['type'] !== null)
            ->setMediaType($info['type'] !== null ? MediaType::from($info['type']) : null)
            ->setReady($info['ready'])
            ->setStatus(MediaStatus::from($info['status']))
            ->setProcessingPercentage((float) $info['processing_percentage']);
        foreach ($info['files'] as $f) {
            $file = new MediaFile($media, $f['file_name'], $f['content_type'], $f['media_file_id']);
            $file->setOriginal(str_starts_with($f['file_name'], 'original.'))
                ->setWidth($f['size']['width'] ?? null)
                ->setHeight($f['size']['height'] ?? null)
                ->setBitRate($f['size']['bitrate'] ?? null);
            $media->addFile($file);
        }
        $this->em->persist($media);
        $this->em->flush();
        return $media;
    }

    private function get(string $path, array $params, ?string $key, array $headers = []): void
    {
        if ($key !== null) {
            $params['apikey'] = $key;
        }
        $this->browser->request('GET', $path, $params, [], $headers);
    }

    private function body(): string
    {
        return (string) $this->browser->getResponse()->getContent();
    }

    // ── mediaInfo ────────────────────────────────────────────────────────────

    #[DataProvider('mediaInfoFixtures')]
    public function testMediaInfoMatchesLegacyByteForByte(string $fixture): void
    {
        $data = self::fixture($fixture);
        $info = $data['response']['json'];
        $id   = $data['request']['params']['media_id'];
        $this->mediaFromLegacy($id, $info);

        $this->get('/api/mediaInfo', ['media_id' => $id], $this->keys[$info['owner']]);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame($data['response']['raw'], $this->body());
    }

    /** Renamed clients (Events → HealthEdTrainingsMESD) report their current name as owner. */
    public function testRenamedClientReportsCurrentName(): void
    {
        $data = self::fixture('mediaInfo_Events_pdf');
        $id   = $data['request']['params']['media_id'];
        $this->mediaFromLegacy($id, $data['response']['json']);
        $client = $this->client('Events');
        $client->setName('HealthEdTrainingsMESD')->setLegacyName('Events');
        $this->em->flush();

        $this->get('/api/mediaInfo', ['media_id' => $id], $this->keys['Events']);

        self::assertSame(
            str_replace('"owner":"Events"', '"owner":"HealthEdTrainingsMESD"', $data['response']['raw']),
            $this->body(),
        );
    }

    public function testMediaInfoAcceptsKeyInHeader(): void
    {
        $data = self::fixture('mediaInfo_Events_pdf');
        $id   = $data['request']['params']['media_id'];
        $this->mediaFromLegacy($id, $data['response']['json']);

        $this->get('/api/mediaInfo', ['media_id' => $id], null, ['HTTP_X_API_KEY' => $this->keys['Events']]);

        self::assertSame($data['response']['raw'], $this->body());
    }

    public function testMediaInfoMissingParameter(): void
    {
        $this->client('Training');
        $legacy = self::fixture('mediaInfo_missing_param')['response'];

        $this->get('/api/mediaInfo', [], $this->keys['Training']);

        self::assertResponseStatusCodeSame($legacy['status']);
        self::assertSame($legacy['text'], $this->body());
    }

    public function testMediaInfoUnknownAndMalformedIdsAre404(): void
    {
        $this->client('Training');
        foreach (['mediaInfo_nonexistent', 'mediaInfo_malformed_id'] as $name) {
            $legacy = self::fixture($name);
            $this->get('/api/mediaInfo', $legacy['request']['params'], $this->keys['Training']);
            self::assertResponseStatusCodeSame(404, $name);
        }
    }

    public function testMediaInfoForAnotherAppsMediaIs404(): void
    {
        $data = self::fixture('mediaInfo_Events_pdf');
        $id   = $data['request']['params']['media_id'];
        $this->mediaFromLegacy($id, $data['response']['json']);
        $this->client('Training');

        $this->get('/api/mediaInfo', ['media_id' => $id], $this->keys['Training']);

        self::assertResponseStatusCodeSame(self::fixture('mediaInfo_other_app')['response']['status']);
    }

    public function testMissingOrInvalidKeyIsRejectedLikeLegacy(): void
    {
        foreach (['mediaInfo_no_key' => null, 'mediaInfo_bad_key' => 'not-a-real-key'] as $name => $key) {
            $legacy = self::fixture($name)['response'];
            $this->get('/api/mediaInfo', ['media_id' => str_repeat('0', 24)], $key);
            self::assertResponseStatusCodeSame($legacy['status'], $name);
            self::assertSame($legacy['text'], $this->body(), $name);
        }
    }

    public function testInactiveKeyIsRejected(): void
    {
        $client = $this->client('Training');
        $client->setIsActive(false);
        $this->em->flush();

        $this->get('/api/mediaInfo', ['media_id' => str_repeat('0', 24)], $this->keys['Training']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testWrongMethodsAre405(): void
    {
        $this->client('Training');
        $this->browser->request('GET', '/api/requestUploadTicket', ['apikey' => $this->keys['Training']]);
        self::assertResponseStatusCodeSame(405);
        $this->browser->request('POST', '/api/mediaInfo', ['apikey' => $this->keys['Training']]);
        self::assertResponseStatusCodeSame(405);
        $this->browser->request('GET', '/api/uploadMedia/' . str_repeat('0', 24));
        self::assertResponseStatusCodeSame(405);
    }

    // ── requestMedia ─────────────────────────────────────────────────────────

    private function writeFile(MediaFile $file, string $contents): void
    {
        $path = static::getContainer()->get(MediaStorage::class)->absolutePath($file);
        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, $contents);
    }

    public function testRequestMediaServesOwnFileWithStoredContentType(): void
    {
        $data  = self::fixture('mediaInfo_Training_image');
        $media = $this->mediaFromLegacy($data['request']['params']['media_id'], $data['response']['json']);
        $file  = $media->getFiles()->last();
        self::assertInstanceOf(MediaFile::class, $file);
        $this->writeFile($file, 'PNGDATA');

        $this->get('/api/requestMedia', ['media_file_id' => $file->getId()], $this->keys['Training']);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertResponseHeaderSame('Accept-Ranges', 'bytes');
        self::assertSame('PNGDATA', $this->browser->getInternalResponse()->getContent());
    }

    /** Deliberate fix: legacy served any app's file to any key (fixture requestMedia_other_app = 200). */
    public function testRequestMediaForAnotherAppsFileIs404(): void
    {
        self::assertSame(200, self::fixture('requestMedia_other_app')['response']['status']);

        $data  = self::fixture('mediaInfo_Events_pdf');
        $media = $this->mediaFromLegacy($data['request']['params']['media_id'], $data['response']['json']);
        $file  = $media->getFiles()->first();
        self::assertInstanceOf(MediaFile::class, $file);
        $this->writeFile($file, '%PDF');
        $this->client('Training');

        $this->get('/api/requestMedia', ['media_file_id' => $file->getId()], $this->keys['Training']);

        self::assertResponseStatusCodeSame(404);
    }

    /** Deliberate fix: legacy answered 500 for unknown IDs. */
    public function testRequestMediaUnknownIdIs404(): void
    {
        $this->client('Training');
        $this->get('/api/requestMedia', ['media_file_id' => str_repeat('0', 24)], $this->keys['Training']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRequestMediaMissingParameter(): void
    {
        $this->client('Training');
        $legacy = self::fixture('requestMedia_missing_param')['response'];

        $this->get('/api/requestMedia', [], $this->keys['Training']);

        self::assertResponseStatusCodeSame($legacy['status']);
        self::assertSame($legacy['text'], $this->body());
    }

    // ── upload flow ──────────────────────────────────────────────────────────

    public function testUploadMediaOptionsMatchesLegacyCors(): void
    {
        $legacy = self::fixture('uploadMedia_OPTIONS')['response'];

        $this->browser->request('OPTIONS', '/api/uploadMedia/' . str_repeat('0', 24));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($legacy['text'], $this->body());
        foreach ($legacy['headers'] as $name => $value) {
            if (str_starts_with($name, 'access-control-')) {
                self::assertResponseHeaderSame($name, $value);
            }
        }
    }

    public function testUploadMediaRejectionsMatchLegacy(): void
    {
        $used = $this->mediaFromLegacy(
            self::fixture('mediaInfo_Events_pdf')['request']['params']['media_id'],
            self::fixture('mediaInfo_Events_pdf')['response']['json'],
        );
        $expired = new Media($this->client('Events'), new \DateTimeImmutable('-1 minute'));
        $this->em->persist($expired);
        $this->em->flush();

        $cases = [
            'uploadMedia_nonexistent' => str_repeat('0', 24),
            'uploadMedia_used'        => $used->getId(),
            'uploadMedia_expired'     => $expired->getId(),
        ];
        foreach ($cases as $name => $ticket) {
            $legacy = self::fixture($name)['response'];
            $this->browser->request('POST', '/api/uploadMedia/' . $ticket);
            self::assertResponseStatusCodeSame($legacy['status'], $name);
            self::assertSame($legacy['text'], $this->body(), $name);
            self::assertResponseHeaderSame('access-control-allow-origin', '*');
        }
    }

    public function testRequestUploadTicketResponseShape(): void
    {
        $this->client('Training');

        $before = new \DateTimeImmutable('+2 hours -5 seconds');
        $this->browser->request('POST', '/api/requestUploadTicket?apikey=' . $this->keys['Training'], [
            'callback_route' => 'https://consumer.example/callback',
        ]);
        $after = new \DateTimeImmutable('+2 hours +5 seconds');

        self::assertResponseStatusCodeSame(200);
        $body = json_decode($this->body(), true);
        self::assertIsArray($body);
        self::assertSame(['upload_route', 'media_id', 'expiration'], array_keys($body));
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $body['media_id']);
        self::assertSame('http://localhost/api/uploadMedia/' . $body['media_id'], $body['upload_route']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $body['expiration']);
        $expires = (new \DateTimeImmutable($body['expiration']))->getTimestamp();
        self::assertGreaterThanOrEqual($before->getTimestamp(), $expires);
        self::assertLessThanOrEqual($after->getTimestamp(), $expires);

        $media = $this->em->find(Media::class, $body['media_id']);
        self::assertInstanceOf(Media::class, $media);
        self::assertTrue($media->isHideToOthers());
        self::assertSame('https://consumer.example/callback', $media->getCallback());
        self::assertSame('Training', $media->getClient()->getName());

        // A fresh ticket reports like the legacy "ticket" fixtures.
        $this->get('/api/mediaInfo', ['media_id' => $body['media_id']], $this->keys['Training']);
        $legacyTicket = self::fixture('mediaInfo_Training_ticket')['response']['raw'];
        self::assertSame($legacyTicket, $this->body());
    }
}
