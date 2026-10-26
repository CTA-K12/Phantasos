<?php

namespace App\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests for the dev-login auth bypass.
 *
 * These tests run in APP_ENV=test, so APP_ENV !== 'dev' is always true.
 * Both routes must return 404 regardless of APP_DEV_AUTH_ENABLED — proving
 * that the kernel environment is the primary guard, not just the flag.
 */
class DevLoginControllerTest extends WebTestCase
{
    public function test_dev_login_page_returns_404_in_test_environment(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dev-login');

        $this->assertResponseStatusCodeSame(404);
    }

    public function test_dev_login_post_returns_404_in_test_environment(): void
    {
        $client = static::createClient();
        $client->request('POST', '/dev-login', ['email' => 'staff@cta.org']);

        $this->assertResponseStatusCodeSame(404);
    }
}
