<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\ApiClient;
use App\Entity\AuditLog;
use App\Entity\Media;
use App\Entity\MediaFile;
use App\Enum\MediaStatus;
use App\Enum\MediaType;
use App\Message\ProcessMedia;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

class ProcessingControllerTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $user = static::getContainer()->get(UserRepository::class)->findByEmail('dcramble@mesd.k12.or.us');
        self::assertNotNull($user);
        $this->browser->loginUser($user);
    }

    private function media(MediaStatus $status, string $created = '-1 day', bool $withOriginal = true): Media
    {
        $client = $this->em->getRepository(ApiClient::class)->findOneBy(['name' => 'Training'])
            ?? new ApiClient('Training', bin2hex(random_bytes(16)));
        $this->em->persist($client);
        $media = (new Media($client, new \DateTimeImmutable('-1 day')))
            ->setUploaded(true)->setMediaType(MediaType::Video)->setStatus($status)
            ->setCreatedAt(new \DateTimeImmutable($created));
        if ($withOriginal) {
            $media->addFile((new MediaFile($media, 'original.mp4', 'video/mp4'))->setOriginal(true));
        }
        $this->em->persist($media);
        $this->em->flush();
        return $media;
    }

    public function testListsFailedAndStuckButNotHealthyMedia(): void
    {
        $failed  = $this->media(MediaStatus::Failed);
        $stuck   = $this->media(MediaStatus::Queued, '-7 hours');
        $recent  = $this->media(MediaStatus::Queued, '-10 minutes');
        $ready   = $this->media(MediaStatus::Ready);

        $this->browser->request('GET', '/admin/processing');

        self::assertResponseIsSuccessful();
        $html = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString($failed->getId(), $html);
        self::assertStringContainsString($stuck->getId(), $html);
        self::assertStringNotContainsString($recent->getId(), $html);
        self::assertStringNotContainsString($ready->getId(), $html);
        self::assertSelectorTextContains('aside .nav-badge', '2');
    }

    public function testRetryRequeuesAndAudits(): void
    {
        $failed = $this->media(MediaStatus::Failed);
        $this->browser->request('GET', '/admin/processing');
        $token = $this->browser->getCrawler()
            ->filter('form[action="/admin/processing/' . $failed->getId() . '/retry"] input[name="_token"]')->attr('value');

        $this->browser->request('POST', '/admin/processing/' . $failed->getId() . '/retry', ['_token' => $token]);

        self::assertResponseRedirects('/admin/processing');
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $sent = array_map(static fn ($e) => $e->getMessage(), $transport->getSent());
        self::assertEquals([new ProcessMedia($failed->getId())], $sent);

        $this->em->clear();
        $media = $this->em->find(Media::class, $failed->getId());
        self::assertInstanceOf(Media::class, $media);
        self::assertSame(MediaStatus::Queued, $media->getStatus());
        self::assertInstanceOf(AuditLog::class, $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'media.reprocessed']));
    }

    public function testRetryAllSkipsMediaWithoutOriginal(): void
    {
        $this->media(MediaStatus::Failed);
        $this->media(MediaStatus::Failed, '-1 day', withOriginal: false);
        $this->browser->request('GET', '/admin/processing');
        $token = $this->browser->getCrawler()
            ->filter('form[action="/admin/processing/retry-all"] input[name="_token"]')->attr('value');

        $this->browser->request('POST', '/admin/processing/retry-all', ['_token' => $token]);
        $this->browser->followRedirect();

        self::assertSelectorTextContains('.flash', '1 media queued');
    }

    public function testRetryRejectsForgedToken(): void
    {
        $failed = $this->media(MediaStatus::Failed);

        $this->browser->request('POST', '/admin/processing/' . $failed->getId() . '/retry', ['_token' => 'forged']);

        self::assertResponseRedirects('/admin/processing');
        $this->em->clear();
        self::assertSame(MediaStatus::Failed, $this->em->find(Media::class, $failed->getId())?->getStatus());
    }
}
