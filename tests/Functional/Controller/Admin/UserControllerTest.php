<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserControllerTest extends WebTestCase
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

    // ── Access control ──────────────────────────────────────────────────────

    public function test_index_blocked_for_staff(): void
    {
        $this->loginAs('staff@cta.org');
        $this->client->request('GET', '/admin/users');
        $this->assertResponseStatusCodeSame(403);
    }

    public function test_index_blocked_for_supervisor(): void
    {
        $this->loginAs('supervisor@cta.org');
        $this->client->request('GET', '/admin/users');
        $this->assertResponseStatusCodeSame(403);
    }

    public function test_index_loads_for_admin(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#breadcrumbs', 'Users');
    }

    // ── Index content ────────────────────────────────────────────────────────

    public function test_index_lists_all_users(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
        // All fixture users should appear
        $this->assertSelectorTextContains('body', 'staff@cta.org');
        $this->assertSelectorTextContains('body', 'supervisor@cta.org');
        $this->assertSelectorTextContains('body', 'pending@cta.org');
    }

    // ── New user ─────────────────────────────────────────────────────────────

    public function test_new_form_loads(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $this->client->request('GET', '/admin/users/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('article header', 'New User');
    }

    public function test_create_user_redirects_on_success(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $crawler = $this->client->request('GET', '/admin/users/new');
        $token   = $crawler->filter('input[name="user[_token]"]')->attr('value');

        $this->client->request('POST', '/admin/users/new', [
            'user' => [
                'displayName' => 'New Test User',
                'email'       => 'newuser@test.com',
                'role'        => 'ROLE_STAFF',
                'isActive'    => '1',
                '_token'      => $token,
            ],
        ]);

        $this->assertResponseRedirects('/admin/users');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'created successfully');
    }

    public function test_create_user_shows_error_for_blank_name(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $crawler = $this->client->request('GET', '/admin/users/new');
        $token   = $crawler->filter('input[name="user[_token]"]')->attr('value');

        $this->client->request('POST', '/admin/users/new', [
            'user' => [
                'displayName' => '',
                'email'       => 'someone@test.com',
                'role'        => 'ROLE_STAFF',
                '_token'      => $token,
            ],
        ]);

        $this->assertResponseStatusCodeSame(422); // Form invalid — re-renders with errors
        $this->assertSelectorTextContains('body', 'Display name is required');
    }

    // ── Edit user ─────────────────────────────────────────────────────────────

    public function test_edit_form_loads(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'staff@cta.org']);

        $this->client->request('GET', '/admin/users/' . $user->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('article header', 'Edit');
    }

    public function test_edit_form_prepopulates_user_data(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'staff@cta.org']);

        $crawler = $this->client->request('GET', '/admin/users/' . $user->getId() . '/edit');
        $this->assertResponseIsSuccessful();

        // Display name field should be pre-filled
        $nameInput = $crawler->filter('input[name="user[displayName]"]')->attr('value');
        $this->assertSame('Staff Member', $nameInput);
    }

    public function test_update_user_redirects_on_success(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $user    = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'staff@cta.org']);
        $editUrl = '/admin/users/' . $user->getId() . '/edit';

        $crawler = $this->client->request('GET', $editUrl);
        $token   = $crawler->filter('input[name="user[_token]"]')->attr('value');

        $this->client->request('POST', $editUrl, [
            'user' => [
                'displayName' => 'Staff Member',
                'email'       => 'staff@cta.org',
                'role'        => 'ROLE_STAFF',
                'isActive'    => '1',
                '_token'      => $token,
            ],
        ]);

        $this->assertResponseRedirects('/admin/users');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'updated successfully');
    }

    // ── Toggle active ─────────────────────────────────────────────────────────

    public function test_toggle_active_changes_status(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $user      = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'pending@cta.org']);
        $userId    = (string) $user->getId();
        $toggleUrl = '/admin/users/' . $userId . '/toggle';

        $crawler = $this->client->request('GET', '/admin/users');
        $token   = $crawler->filter('form[action="' . $toggleUrl . '"] input[name="_token"]')->attr('value');

        $this->client->request('POST', $toggleUrl, ['_token' => $token]);

        $this->assertResponseRedirects('/admin/users');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Pending Account');
    }

    public function test_toggle_active_rejects_invalid_csrf(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'pending@cta.org']);

        $this->client->request('POST', '/admin/users/' . $user->getId() . '/toggle', [
            '_token' => 'invalid-token',
        ]);

        $this->assertResponseRedirects('/admin/users');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Invalid security token');
    }
}
