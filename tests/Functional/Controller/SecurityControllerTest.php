<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SecurityControllerTest extends WebTestCase
{
    public function testLoginPageIsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.pp-login-logo-name', 'Phantasos');
    }

    public function testUnauthenticatedUserRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard');

        $this->assertResponseRedirects('/login');
    }

    public function testPendingApprovalPageIsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pending-approval');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h3', 'Account pending approval');
    }

    public function testAuthenticatedActiveUserCanAccessDashboard(): void
    {
        $client = static::createClient();

        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        $user = $repo->findByEmail('dcramble@mesd.k12.or.us');

        $this->assertNotNull($user);

        $client->loginUser($user);
        $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
    }

    public function testInactiveUserExistsInDatabase(): void
    {
        $client = static::createClient();

        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        $user = $repo->findByEmail('pending@cta.org');

        $this->assertNotNull($user);
        $this->assertFalse($user->isActive());
        // Note: Active status enforcement happens through GoogleAuthenticator
        // which redirects inactive users to the pending-approval page.
        // The loginUser() test helper bypasses the authenticator chain.
    }

    public function testLogoutRedirectsToLogin(): void
    {
        $client = static::createClient();

        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        $user = $repo->findByEmail('dcramble@mesd.k12.or.us');

        $client->loginUser($user);
        $client->request('GET', '/logout');

        $this->assertResponseRedirects();
    }
}
