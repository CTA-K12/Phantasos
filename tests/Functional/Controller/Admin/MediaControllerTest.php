<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\ApiClient;
use App\Entity\AuditLog;
use App\Entity\Media;
use App\Entity\MediaFile;
use App\Enum\MediaStatus;
use App\Enum\MediaType;
use App\Repository\UserRepository;
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

class MediaControllerTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->em      = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(static::getContainer()->get(MediaStorage::class)->getMediaRoot());
        parent::tearDown();
    }

    private function login(string $email = 'dcramble@mesd.k12.or.us'): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findByEmail($email);
        self::assertNotNull($user);
        $this->browser->loginUser($user);
    }

    private function client(string $name): ApiClient
    {
        $c = new ApiClient($name, bin2hex(random_bytes(16)));
        $this->em->persist($c);
        return $c;
    }

    /** @param list<array{string, string}> $files */
    private function media(ApiClient $client, string $id, array $files = [['original.png', 'image/png']], bool $onDisk = true): Media
    {
        $m = (new Media($client, new \DateTimeImmutable('+1 hour'), $id))
            ->setUploaded($files !== [])->setMediaType($files !== [] ? MediaType::Image : null)
            ->setStatus($files !== [] ? MediaStatus::Ready : MediaStatus::Queued)->setReady($files !== []);
        foreach ($files as $i => [$name, $type]) {
            $f = (new MediaFile($m, $name, $type, substr($id, 0, 20) . sprintf('%04x', $i)))->setOriginal($i === 0);
            $m->addFile($f);
            if ($onDisk) {
                $path = static::getContainer()->get(MediaStorage::class)->absolutePath($f);
                @mkdir(dirname($path), 0o775, true);
                file_put_contents($path, 'bytes-of-' . $name);
            }
        }
        $this->em->persist($m);
        $this->em->flush();
        return $m;
    }

    public function testNonAdminsAreForbidden(): void
    {
        $this->login('supervisor@cta.org');
        $this->browser->request('GET', '/admin/media');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSearchByPrefixClientAndFileId(): void
    {
        $a = $this->client('DocGuard');
        $b = $this->client('Training');
        $this->media($a, 'aaaa11111111111111111111');
        $this->media($a, 'aaaa22222222222222222222');
        $this->media($b, 'bbbb33333333333333333333');
        $this->media($b, 'cccc44444444444444444444', []); // unused ticket
        $this->login();

        $this->browser->request('GET', '/admin/media', ['id' => 'AAAA']);
        $html = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('aaaa11111111111111111111', $html);
        self::assertStringContainsString('aaaa22222222222222222222', $html);
        self::assertStringNotContainsString('bbbb33333333333333333333', $html);

        $this->browser->request('GET', '/admin/media', ['client' => $b->getId()->toRfc4122()]);
        $html = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('bbbb33333333333333333333', $html);
        self::assertStringNotContainsString('cccc44444444444444444444', $html, 'unused tickets hidden by default');

        $this->browser->request('GET', '/admin/media', ['client' => $b->getId()->toRfc4122(), 'tickets' => '1']);
        self::assertStringContainsString('cccc44444444444444444444', (string) $this->browser->getResponse()->getContent());

        // A file ID finds its media; a full unique ID jumps straight to it.
        $this->browser->request('GET', '/admin/media', ['id' => 'bbbb333333333333333333330000']);
        self::assertResponseIsSuccessful();
        $this->browser->request('GET', '/admin/media', ['id' => 'bbbb33333333333333333333']);
        self::assertResponseRedirects('/admin/media/bbbb33333333333333333333');
    }

    public function testViewIsAuditedAndShowsFiles(): void
    {
        $m = $this->media($this->client('DocGuard'), 'dddd55555555555555555555', [['original.pdf', 'application/pdf'], ['note.html', 'text/html']]);
        $this->login();

        $this->browser->request('GET', '/admin/media/' . $m->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'original.pdf');
        self::assertTrue($this->browser->getResponse()->headers->hasCacheControlDirective('no-store'));
        $log = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'media.admin_viewed']);
        self::assertInstanceOf(AuditLog::class, $log);
        self::assertSame($m->getId(), $log->getEntityId());
    }

    public function testOpeningFilesIsAuditedAndSafe(): void
    {
        $m = $this->media($this->client('DocGuard'), 'eeee66666666666666666666', [['original.png', 'image/png'], ['output.html', 'text/html']]);
        [$png, $html] = $m->getFiles()->toArray();
        $this->login();

        $this->browser->request('GET', sprintf('/admin/media/%s/files/%s', $m->getId(), $png->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame('bytes-of-original.png', $this->browser->getInternalResponse()->getContent());
        self::assertStringStartsWith('inline;', (string) $this->browser->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('sandbox', (string) $this->browser->getResponse()->headers->get('Content-Security-Policy'));

        // HTML is never rendered inline on this site.
        $this->browser->request('GET', sprintf('/admin/media/%s/files/%s', $m->getId(), $html->getId()));
        self::assertStringStartsWith('attachment;', (string) $this->browser->getResponse()->headers->get('Content-Disposition'));

        self::assertCount(2, $this->em->getRepository(AuditLog::class)->findBy(['action' => 'media.admin_file_opened']));
    }

    public function testFileFromAnotherMediaOrMissingOnDiskIs404(): void
    {
        $client = $this->client('Training');
        $a = $this->media($client, 'ffff77777777777777777777');
        $b = $this->media($client, 'ffff88888888888888888888', onDisk: false);
        $this->login();

        $this->browser->request('GET', sprintf('/admin/media/%s/files/%s', $a->getId(), $b->getFiles()->first()->getId()));
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', sprintf('/admin/media/%s/files/%s', $b->getId(), $b->getFiles()->first()->getId()));
        self::assertResponseStatusCodeSame(404);
    }
}
