<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * POSTs `media_id` (form-encoded) to a consumer's callback URL once media is ready,
 * as the legacy Alerter did. Failures are logged and reported, never retried.
 */
class CallbackNotifier
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly float $timeout = 15.0,
    ) {
    }

    public function notifyReady(string $callbackUrl, string $mediaId): bool
    {
        $callbackUrl = self::normalize($callbackUrl);
        $scheme      = parse_url($callbackUrl, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            $this->logger->warning('Callback skipped: unsupported URL scheme', ['media_id' => $mediaId]);
            return false;
        }

        try {
            $response = $this->httpClient->request('POST', $callbackUrl, [
                'body'          => ['media_id' => $mediaId],
                'timeout'       => $this->timeout,
                'max_redirects' => 0,
            ]);
            return $response->getStatusCode() === 200;
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Callback failed', ['media_id' => $mediaId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * SubDocs registers callbacks without a scheme ("host/path"); the legacy HTTP client
     * (Guzzle 5) assumed http:// for those, and tens of thousands were delivered that way.
     */
    public static function normalize(string $url): string
    {
        $url = trim($url);
        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1 ? $url : 'http://' . $url;
    }
}
