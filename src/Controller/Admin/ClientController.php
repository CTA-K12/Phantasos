<?php

namespace App\Controller\Admin;

use App\Entity\ApiClient;
use App\Form\ApiClientType;
use App\Repository\ApiClientRepository;
use App\Repository\MediaRepository;
use App\Service\ApiKeyIssuer;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * API clients and their keys. A key is displayed exactly once — in the response to
 * the request that created or rotated it — and is never stored or logged in plaintext.
 */
#[Route('/admin/clients')]
#[IsGranted('ROLE_ADMIN')]
class ClientController extends AbstractController
{
    public function __construct(
        private readonly ApiClientRepository $clients,
        private readonly MediaRepository $mediaRepository,
        private readonly ApiKeyIssuer $issuer,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_client_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/client/index.html.twig', [
            'clients' => $this->clients->findAllOrdered(),
            'stats'   => $this->mediaRepository->statsByClient(),
        ]);
    }

    #[Route('/new', name: 'admin_client_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        [$client, $key] = $this->issuer->create('');
        $form = $this->createForm(ApiClientType::class, $client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->clients->save($client, true);
            $this->auditLogger->log('client.created', 'ApiClient', $client->getId()->toRfc4122(), [
                'name' => $client->getName(),
            ]);
            return $this->showKey($client, $key, 'created');
        }

        return $this->render('admin/client/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'admin_client_view', methods: ['GET'])]
    public function view(string $id): Response
    {
        $client = $this->find($id);

        return $this->render('admin/client/view.html.twig', [
            'client' => $client,
            'stats'  => $this->mediaRepository->statsByClient()[$client->getId()->toRfc4122()] ?? null,
            'recent' => $this->mediaRepository->findRecentUploads(10, $client),
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_client_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $client = $this->find($id);
        $before = $client->getName();
        $form   = $this->createForm(ApiClientType::class, $client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->clients->flush();
            $this->auditLogger->log('client.updated', 'ApiClient', $client->getId()->toRfc4122(), array_filter([
                'name'         => $client->getName(),
                'renamed_from' => $before !== $client->getName() ? $before : null,
            ]));
            $this->addFlash('success', sprintf('Client "%s" saved.', $client->getName()));
            return $this->redirectToRoute('admin_client_view', ['id' => $id]);
        }

        return $this->render('admin/client/edit.html.twig', ['form' => $form, 'client' => $client]);
    }

    #[Route('/{id}/rotate', name: 'admin_client_rotate', methods: ['POST'])]
    public function rotate(string $id, Request $request): Response
    {
        $client = $this->find($id);
        if (!$this->isCsrfTokenValid('client-rotate-' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('admin_client_view', ['id' => $id]);
        }

        $key = $this->issuer->rotate($client);
        $this->clients->flush();
        $this->auditLogger->log('client.key_rotated', 'ApiClient', $client->getId()->toRfc4122(), [
            'name'   => $client->getName(),
            'reason' => $request->request->getString('reason'),
        ]);

        return $this->showKey($client, $key, 'rotated');
    }

    #[Route('/{id}/toggle', name: 'admin_client_toggle', methods: ['POST'])]
    public function toggle(string $id, Request $request): Response
    {
        $client = $this->find($id);
        if (!$this->isCsrfTokenValid('client-toggle-' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('admin_client_view', ['id' => $id]);
        }

        $client->setIsActive(!$client->isActive());
        $this->clients->flush();
        $this->auditLogger->log(
            $client->isActive() ? 'client.activated' : 'client.deactivated',
            'ApiClient',
            $client->getId()->toRfc4122(),
            ['name' => $client->getName(), 'reason' => $request->request->getString('reason')],
        );
        $this->addFlash('success', sprintf(
            '"%s" %s.',
            $client->getName(),
            $client->isActive() ? 'is active again; its key works' : 'is deactivated; its key is refused until reactivated',
        ));

        return $this->redirectToRoute('admin_client_view', ['id' => $id]);
    }

    /** Renders the one-time key page. Never cached, never redirected through the session. */
    private function showKey(ApiClient $client, string $key, string $event): Response
    {
        $response = $this->render('admin/client/key.html.twig', [
            'client' => $client,
            'key'    => $key,
            'event'  => $event,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function find(string $id): ApiClient
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }
        return $this->clients->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException();
    }
}
