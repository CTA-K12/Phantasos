<?php

namespace App\Tests\Functional\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HelpControllerTest extends WebTestCase
{
    public function testAuthenticatedUserCanViewHelpPage(): void
    {
        $client = static::createClient();

        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        $user = $repo->findByEmail('staff@cta.org');
        $this->assertNotNull($user);

        $client->loginUser($user);
        $client->request('GET', '/help/app_dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.help-prose');
    }

    public function testUnauthenticatedUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/help/app_dashboard');

        $this->assertResponseRedirects('/login');
    }

    public function testUnknownPageReturnsFallbackContent(): void
    {
        $client = static::createClient();

        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        $user = $repo->findByEmail('staff@cta.org');
        $this->assertNotNull($user);

        $client->loginUser($user);
        $client->request('GET', '/help/nonexistent_route_xyz');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.help-prose');
    }
}
