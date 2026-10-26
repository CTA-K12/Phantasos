<?php

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/login.html.twig');
    }

    #[Route('/connect/google', name: 'app_login_google')]
    public function connectGoogle(ClientRegistry $clientRegistry): Response
    {
        return $clientRegistry
            ->getClient('google')
            ->redirect(['email', 'profile'], []);
    }

    #[Route('/connect/google/check', name: 'app_login_google_check')]
    public function connectGoogleCheck(): never
    {
        // This route is handled by GoogleAuthenticator
        throw new \LogicException('This should be handled by the authenticator.');
    }

    #[Route('/pending-approval', name: 'app_pending_approval')]
    public function pendingApproval(): Response
    {
        return $this->render('security/pending_approval.html.twig');
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('This should be handled by the security firewall.');
    }
}
