<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only login bypass — allows developers to log in as any fixture user
 * without going through Google OAuth.
 *
 * Both conditions must be true for this controller to respond:
 *   1. APP_ENV=dev  (enforced by the kernel; never true in production)
 *   2. APP_DEV_AUTH_ENABLED=true  (opt-in via .env.local)
 *
 * All routes return 404 if either condition is not met.
 */
class DevLoginController extends AbstractController
{
    #[Route('/dev-login', name: 'app_dev_login', methods: ['GET'])]
    public function index(
        #[Autowire(env: 'APP_ENV')] string $appEnv,
        #[Autowire(env: 'APP_DEV_AUTH_ENABLED')] string $devAuthEnabled,
        UserRepository $userRepository,
    ): Response {
        if ($appEnv !== 'dev' || $devAuthEnabled !== 'true') {
            throw $this->createNotFoundException();
        }

        $users = $userRepository->findBy([], ['email' => 'ASC']);

        return $this->render('dev/login.html.twig', ['users' => $users]);
    }

    #[Route('/dev-login', name: 'app_dev_login_as', methods: ['POST'])]
    public function loginAs(
        Request $request,
        UserRepository $userRepository,
        Security $security,
        #[Autowire(env: 'APP_ENV')] string $appEnv,
        #[Autowire(env: 'APP_DEV_AUTH_ENABLED')] string $devAuthEnabled,
    ): Response {
        if ($appEnv !== 'dev' || $devAuthEnabled !== 'true') {
            throw $this->createNotFoundException();
        }

        $email = $request->request->getString('email');
        $user  = $userRepository->findByEmail($email);

        if ($user === null) {
            throw $this->createNotFoundException(sprintf('No user found for email "%s".', $email));
        }

        $response = $security->login($user, firewallName: 'main');

        return $response ?? $this->redirectToRoute('app_dashboard');
    }
}
