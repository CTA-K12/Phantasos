<?php

namespace App\Security;

use App\Entity\ApiClient;
use App\Repository\ApiClientRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authenticates consuming applications by API key on the /api firewall.
 *
 * The key is accepted, in order, from the `X-Api-Key` header, the `apikey` query
 * parameter, or an `apikey` form field. Query and form are the legacy locations and
 * stay supported so existing consumers need no changes; the header keeps keys out of
 * access logs and is preferred for new integrations.
 */
class ApiKeyAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const HEADER = 'X-Api-Key';
    public const PARAM  = 'apikey';

    /** last_used_at is a coarse "is this key still in use" signal; don't write on every request. */
    private const LAST_USED_RESOLUTION = 'PT1H';

    public function __construct(
        private readonly ApiClientRepository $clients,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $apiKey = self::extractKey($request);
        if ($apiKey === '') {
            throw new BadCredentialsException('No API key found.');
        }

        return new SelfValidatingPassport(
            new UserBadge(ApiClient::hashKey($apiKey), function () use ($apiKey): ApiClient {
                $client = $this->clients->findActiveByApiKey($apiKey);
                if ($client === null) {
                    throw new BadCredentialsException('Unknown or inactive API key.');
                }
                return $client;
            }),
        );
    }

    public static function extractKey(Request $request): string
    {
        $header = $request->headers->get(self::HEADER);
        if ($header !== null && $header !== '') {
            return $header;
        }
        $query = $request->query->getString(self::PARAM);
        if ($query !== '') {
            return $query;
        }
        return $request->request->getString(self::PARAM);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $client = $token->getUser();
        if ($client instanceof ApiClient) {
            $now      = new \DateTimeImmutable();
            $lastUsed = $client->getLastUsedAt();
            if ($lastUsed === null || $lastUsed->add(new \DateInterval(self::LAST_USED_RESOLUTION)) < $now) {
                $client->setLastUsedAt($now);
                $this->clients->flush();
            }
        }
        return null;
    }

    /** Same status and body as the legacy system. */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new Response('Authentication Failed. ', Response::HTTP_FORBIDDEN);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new Response('Authentication Failed. ', Response::HTTP_FORBIDDEN);
    }
}
