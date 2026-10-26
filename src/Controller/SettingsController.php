<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_STAFF')]
class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'app_settings')]
    public function index(
        #[Autowire(env: 'DATABASE_URL')] string $databaseUrl,
        #[Autowire(env: 'APP_ENV')] string $appEnv,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $roleLabel = match (true) {
            in_array('ROLE_ADMIN', $user->getRoles())      => 'Admin',
            in_array('ROLE_SUPERVISOR', $user->getRoles()) => 'Supervisor',
            default                                        => 'Staff',
        };

        // Extract only the database name from the connection URL — no credentials exposed
        $urlPath = parse_url($databaseUrl, PHP_URL_PATH);
        $dbName  = ltrim(is_string($urlPath) ? $urlPath : '', '/');

        return $this->render('settings/index.html.twig', [
            'roleLabel' => $roleLabel,
            'dbName'    => $dbName ?: '—',
            'appEnv'    => strtoupper($appEnv),
        ]);
    }
}
