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

}
