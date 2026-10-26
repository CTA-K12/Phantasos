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
        $this->assertSelectorTextContains('h4', 'Dashboard');
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

    public function testDashboardDataReturnsJson(): void
    {
        $client = static::createClient();
        $user   = static::getContainer()->get(UserRepository::class)->findByEmail('supervisor@cta.org');

        $client->loginUser($user);
        $client->request('GET', '/dashboard/data');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('activityByType', $data);
        $this->assertNotEmpty($data['activityByType']);
    }

    public function testDashboardDataHasExpectedStructure(): void
    {
        $client = static::createClient();
        $user   = static::getContainer()->get(UserRepository::class)->findByEmail('dcramble@mesd.k12.or.us');

        $client->loginUser($user);
        $client->request('GET', '/dashboard/data');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertIsArray($data['activityByType']);
        $firstItem = $data['activityByType'][0];
        $this->assertArrayHasKey('label', $firstItem);
        $this->assertArrayHasKey('count', $firstItem);
        $this->assertArrayHasKey('color', $firstItem);
    }

    public function testDashboardDataRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard/data');

        $this->assertResponseRedirects('/login');
    }
}
