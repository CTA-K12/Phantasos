<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\ApiClient;
use App\Entity\AuditLog;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ClientControllerTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->em      = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function loginAs(string $email): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findByEmail($email);
        self::assertNotNull($user);
        $this->browser->loginUser($user);
    }

    private function apiStatus(string $key): int
    {
        // Missing media_id → 400 when the key is accepted, 403 when it is refused.
        $this->browser->request('GET', '/api/mediaInfo', [], [], ['HTTP_X_API_KEY' => $key]);
        return $this->browser->getResponse()->getStatusCode();
    }

    /** @return array{ApiClient, string} */
    private function createViaUi(string $name): array
    {
        $crawler = $this->browser->request('GET', '/admin/clients/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Create client and key')->form([
            'api_client[name]'         => $name,
            'api_client[contactEmail]' => 'owner@mesd.k12.or.us',
        ]);
        $crawler = $this->browser->submit($form);

        self::assertResponseIsSuccessful();
        $headers = $this->browser->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('no-store') && $headers->hasCacheControlDirective('private'));
        $key = (string) $crawler->filter('#api-key')->attr('value');
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $key);

        $client = $this->em->getRepository(ApiClient::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(ApiClient::class, $client);
        return [$client, $key];
    }

    private function postAction(string $path, string $tokenId, array $extra = []): void
    {
        // The view page renders the forms (and their CSRF tokens) for these actions.
        $crawler = $this->browser->getCrawler();
        $token   = $crawler->filter('form[action="' . $path . '"] input[name="_token"]')->attr('value');
        self::assertNotNull($token, $tokenId);
        $this->browser->request('POST', $path, ['_token' => $token] + $extra);
    }

    public function testNonAdminsAreForbidden(): void
    {
        $this->loginAs('supervisor@cta.org');
        $this->browser->request('GET', '/admin/clients');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateShowsKeyOnceAndStoresOnlyItsHash(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        [$client, $key] = $this->createViaUi('Training-QA');

        self::assertSame(hash('sha256', $key), $client->getKeyHash());
        self::assertSame(400, $this->apiStatus($key));

        // The key is not on any later page.
        $this->browser->request('GET', '/admin/clients/' . $client->getId());
        self::assertStringNotContainsString($key, (string) $this->browser->getResponse()->getContent());
        self::assertSelectorTextContains('body', substr($client->getKeyHash(), 0, 12));

        $log = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'client.created']);
        self::assertInstanceOf(AuditLog::class, $log);
        self::assertStringNotContainsString($key, (string) json_encode($log->getMetadata()));
    }

    public function testDuplicateNameIsRejected(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $this->createViaUi('DocGuard');

        $crawler = $this->browser->request('GET', '/admin/clients/new');
        $this->browser->submit($crawler->selectButton('Create client and key')->form(['api_client[name]' => 'DocGuard']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'already exists');
    }

    public function testRotateReplacesTheKeyImmediately(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        [$client, $oldKey] = $this->createViaUi('SubDocs');
        $view = '/admin/clients/' . $client->getId();

        $this->browser->request('GET', $view);
        $this->postAction($view . '/rotate', 'rotate', ['reason' => 'test']);

        self::assertResponseIsSuccessful();
        $newKey = (string) $this->browser->getCrawler()->filter('#api-key')->attr('value');
        self::assertNotSame($oldKey, $newKey);
        self::assertSame(403, $this->apiStatus($oldKey));
        self::assertSame(400, $this->apiStatus($newKey));
    }

    public function testDeactivateRefusesTheKeyAndReactivateRestoresIt(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        [$client, $key] = $this->createViaUi('Events-Dev');
        $view = '/admin/clients/' . $client->getId();

        $this->browser->request('GET', $view);
        $this->postAction($view . '/toggle', 'toggle');
        self::assertResponseRedirects($view);
        self::assertSame(403, $this->apiStatus($key));

        $this->browser->request('GET', $view);
        $this->postAction($view . '/toggle', 'toggle');
        self::assertSame(400, $this->apiStatus($key));
    }

    public function testActionsRequireCsrfToken(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        [$client, $key] = $this->createViaUi('Csrf-Check');

        $this->browser->request('POST', '/admin/clients/' . $client->getId() . '/rotate', ['_token' => 'forged']);

        self::assertResponseRedirects('/admin/clients/' . $client->getId());
        self::assertSame(400, $this->apiStatus($key));
    }

    public function testIndexListsClients(): void
    {
        $this->loginAs('dcramble@mesd.k12.or.us');
        $this->createViaUi('HealthEdTrainingsMESD');

        $this->browser->request('GET', '/admin/clients');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'HealthEdTrainingsMESD');
    }
}
