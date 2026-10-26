<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class GoogleAuthenticator extends OAuth2Authenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly RouterInterface $router,
        private readonly UserRepository $userRepository,
        private readonly string $googleHostedDomain = '',
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'app_login_google_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);

                $email = $googleUser->getEmail();
                $googleId = $googleUser->getId();

                if ($this->googleHostedDomain !== '' && $googleUser->getHostedDomain() !== $this->googleHostedDomain) {
                    throw new CustomUserMessageAuthenticationException(
                        'You must sign in with a @' . $this->googleHostedDomain . ' account.'
                    );
                }

                // Look up by Google ID first, then by email
                $user = $this->userRepository->findByGoogleId($googleId)
                    ?? ($email !== null ? $this->userRepository->findByEmail($email) : null);

                if ($user === null) {
                    throw new CustomUserMessageAuthenticationException(
                        'Your account has not been set up in this application. Please contact your program administrator to request access.'
                    );
                }

                if (!$user->isActive()) {
                    throw new CustomUserMessageAuthenticationException(
                        'Your account is currently inactive. Please contact your program administrator.'
                    );
                }

                // Link Google ID if user was pre-created by email only
                if ($user->getGoogleId() !== $googleId) {
                    $user->setGoogleId($googleId);
                }

                // Update profile data on each login
                $user->setDisplayName($googleUser->getName() ?? $user->getDisplayName());
                $user->setAvatarUrl($googleUser->getAvatar());
                $user->setLastLoginAt(new \DateTimeImmutable());

                $this->entityManager->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->router->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());

        $request->getSession()->getFlashBag()->add('danger', $message ?: 'Authentication failed. Please try again.');

        return new RedirectResponse($this->router->generate('app_login'));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->router->generate('app_login'));
    }
}
