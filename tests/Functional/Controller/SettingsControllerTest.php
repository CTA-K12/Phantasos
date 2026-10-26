<?php

namespace App\Tests\Functional\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SettingsControllerTest extends WebTestCase
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
        $this->client->request('GET', '/settings');

        $this->assertResponseRedirects('/login');
    }

    public function test_settings_page_loads_for_staff(): void
    {
        $this->loginAs('staff@cta.org');
        $this->client->request('GET', '/settings');

        $this->assertResponseIsSuccessful();
    }

    public function test_settings_page_loads_for_supervisor(): void
    {
        $this->loginAs('supervisor@cta.org');
        $this->client->request('GET', '/settings');

        $this->assertResponseIsSuccessful();
    }

    public function test_settings_page_loads_for_admin(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $this->client->request('GET', '/settings');

        $this->assertResponseIsSuccessful();
    }

    public function test_displays_staff_role_label(): void
    {
        $this->loginAs('staff@cta.org');
        $this->client->request('GET', '/settings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Staff');
    }

    public function test_displays_supervisor_role_label(): void
    {
        $this->loginAs('supervisor@cta.org');
        $this->client->request('GET', '/settings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Supervisor');
    }

    public function test_displays_admin_role_label(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $this->client->request('GET', '/settings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Admin');
    }
}
