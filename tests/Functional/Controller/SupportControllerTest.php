<?php

namespace App\Tests\Functional\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SupportControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function loginAs(string $email): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        $this->client->loginUser($user);
    }

    public function test_unauthenticated_redirects_to_login(): void
    {
        $this->client->request('GET', '/support');

        $this->assertResponseRedirects('/login');
    }

    public function test_support_page_loads_for_staff(): void
    {
        $this->loginAs('staff@cta.org');
        $this->client->request('GET', '/support');

        $this->assertResponseIsSuccessful();
    }

    public function test_support_page_loads_for_supervisor(): void
    {
        $this->loginAs('supervisor@cta.org');
        $this->client->request('GET', '/support');

        $this->assertResponseIsSuccessful();
    }

    public function test_support_page_loads_for_admin(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $this->client->request('GET', '/support');

        $this->assertResponseIsSuccessful();
    }

    public function test_displays_user_display_name(): void
    {
        $this->loginAs('staff@cta.org');
        $this->client->request('GET', '/support');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Staff Member');
    }

    public function test_displays_user_email(): void
    {
        $this->loginAs('staff@cta.org');
        $this->client->request('GET', '/support');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'staff@cta.org');
    }

    public function test_displays_role_label_for_staff(): void
    {
        $this->loginAs('staff@cta.org');
        $this->client->request('GET', '/support');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Staff');
    }

    public function test_displays_role_label_for_supervisor(): void
    {
        $this->loginAs('supervisor@cta.org');
        $this->client->request('GET', '/support');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Supervisor');
    }

    public function test_displays_role_label_for_admin(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $this->client->request('GET', '/support');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Admin');
    }

    public function test_displays_database_name(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $this->client->request('GET', '/support');

        $this->assertResponseIsSuccessful();
        // DB name is extracted from DATABASE_URL — should not be the fallback dash
        $content = $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('>—<', $content);
    }

    public function test_freshservice_link_present(): void
    {
        $this->loginAs('staff@cta.org');
        $this->client->request('GET', '/support');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a[href*="freshservice"]');
    }
}
