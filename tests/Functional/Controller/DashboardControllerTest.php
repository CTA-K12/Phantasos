<?php

namespace App\Tests\Functional\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardControllerTest extends WebTestCase
{
    public function testDashboardLoadsForSupervisor(): void
    {
        $client = static::createClient();
        $user   = static::getContainer()->get(UserRepository::class)->findByEmail('supervisor@cta.org');

        $client->loginUser($user);
        $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#breadcrumbs', 'Dashboard');
        $this->assertSelectorTextContains('.stat-row', 'media stored');
    }

    public function testDashboardLoadsForStaff(): void
    {
        $client = static::createClient();
        $user   = static::getContainer()->get(UserRepository::class)->findByEmail('staff@cta.org');

        $client->loginUser($user);
        $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
    }

    public function testDashboardRedirectsUnauthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard');

        $this->assertResponseRedirects('/login');
    }

    public function testChartsReflectUploadsAndDownloads(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $api    = new \App\Entity\ApiClient('Training', 'k-' . bin2hex(random_bytes(8)));
        $em->persist($api);
        $media = (new \App\Entity\Media($api, new \DateTimeImmutable('+1 hour')))->setUploaded(true);
        $em->persist($media);
        $log = (new \App\Entity\AuditLog())->setAction('media.served')->setMetadata(['client' => 'Training']);
        $em->persist($log);
        $em->flush();

        $stats = static::getContainer()->get(\App\Service\DashboardStats::class);
        $uploads = $stats->uploadsPerDay(30);
        self::assertCount(30, $uploads['days']);
        self::assertSame('Training', $uploads['series'][0]['name']);
        self::assertSame(1, array_sum($uploads['series'][0]['data']));
        self::assertSame([['client' => 'Training', 'n' => 1]], $stats->downloadsPerClient(30));

        $user = static::getContainer()->get(UserRepository::class)->findByEmail('supervisor@cta.org');
        $client->loginUser($user);
        $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#chart-uploads');
        self::assertSelectorExists('#chart-downloads');
    }
}
