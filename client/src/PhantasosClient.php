<?php

declare(strict_types=1);

namespace App\Phantasos;

use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Phantasos API client. Server-side only: the app receives uploads and streams files back to its
 * users itself, so browsers never see a Phantasos URL or the API key.
 *
 * The key travels in the X-Api-Key header, never in a URL, so it can't end up in access logs.
 */
final class PhantasosClient implements MediaStore
{
    private const ID = '/^[0-9a-f]{24}$/';

    private readonly string $baseUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $baseUrl,
        #[\SensitiveParameter] private readonly string $apiKey,
    ) {
        $baseUrl = rtrim($baseUrl, '/');
        $scheme  = parse_url($baseUrl, PHP_URL_SCHEME);
        $host    = parse_url($baseUrl, PHP_URL_HOST);
        if ($host === null || $host === false || !in_array($scheme, ['https', 'http'], true)) {
            throw new \InvalidArgumentException('PHANTASOS_URL must be an absolute URL, e.g. https://phantasos.apps.cascadetech.org');
        }
        if ($scheme === 'http' && !in_array($host, ['localhost', '127.0.0.1'], true)) {
            throw new \InvalidArgumentException('PHANTASOS_URL must use https (http is allowed for localhost only).');
        }
        if ($apiKey === '') {
            throw new \InvalidArgumentException('PHANTASOS_API_KEY is empty.');
        }
        $this->baseUrl = $baseUrl;
    }

    public function upload(string $path, ?string $fileName = null, ?string $callbackUrl = null): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('Cannot read "%s".', $path));
        }

        // 1. A one-time upload ticket.
        $form = ['hide_to_others' => 'true'];
        if ($callbackUrl !== null) {
            $form['callback_route'] = $callbackUrl;
        }
        $ticket = $this->send('POST', '/api/requestUploadTicket', ['body' => $form], 'requestUploadTicket');
        if ($ticket->getStatusCode() !== 200) {
            throw $this->error($ticket, 'requestUploadTicket');
        }
        $body        = $this->decode($ticket);
        $mediaId     = is_string($body['media_id'] ?? null) ? $body['media_id'] : '';
        $uploadRoute = is_string($body['upload_route'] ?? null) ? $body['upload_route'] : '';
        if (!preg_match(self::ID, $mediaId) || $uploadRoute === '') {
            throw new PhantasosException('Unexpected requestUploadTicket response.');
        }
        // The file only ever goes to the server the app is configured for.
        if (parse_url($uploadRoute, PHP_URL_HOST) !== parse_url($this->baseUrl, PHP_URL_HOST)
            || parse_url($uploadRoute, PHP_URL_SCHEME) !== parse_url($this->baseUrl, PHP_URL_SCHEME)) {
            throw new PhantasosException('Phantasos returned an upload route on another host; not sending the file.');
        }

        // 2. The file itself. The route is the ticket: no key is sent with it.
        $data = new FormDataPart(['file_data' => DataPart::fromPath($path, $fileName ?? basename($path))]);
        try {
            $response = $this->httpClient->request('POST', $uploadRoute, [
                'headers'       => $data->getPreparedHeaders()->toArray(),
                'body'          => $data->bodyToIterable(),
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if ($status === 400) {
                $reason = trim($response->getContent(false));
                throw new UploadRejectedException($reason !== '' ? $reason : 'Phantasos did not accept the file.');
            }
            if ($status !== 200) {
                throw $this->error($response, 'uploadMedia');
            }
        } catch (HttpClientException $e) {
            throw new PhantasosException('uploadMedia failed: ' . $e->getMessage(), 0, $e);
        }

        return $mediaId;
    }

    public function info(string $mediaId): MediaInfo
    {
        if (!preg_match(self::ID, $mediaId)) {
            throw new MediaNotFoundException('Media was not found');
        }
        $response = $this->send('GET', '/api/mediaInfo', ['query' => ['media_id' => $mediaId]], 'mediaInfo');
        if ($response->getStatusCode() !== 200) {
            throw $this->error($response, 'mediaInfo');
        }

        return MediaInfo::fromArray($mediaId, $this->decode($response));
    }

    public function fetch(string $mediaFileId, ?string $range = null): FileStream
    {
        if (!preg_match(self::ID, $mediaFileId)) {
            throw new MediaNotFoundException('Media was not found');
        }
        $headers = $range !== null && $range !== '' ? ['Range' => $range] : [];
        $response = $this->send('GET', '/api/requestMedia', [
            'query'   => ['media_file_id' => $mediaFileId],
            'headers' => $headers,
            'buffer'  => false,
        ], 'requestMedia');

        try {
            $status = $response->getStatusCode();
            if ($status !== 200 && $status !== 206) {
                throw $this->error($response, 'requestMedia');
            }
            $headers = $response->getHeaders(false);
        } catch (HttpClientException $e) {
            throw new PhantasosException('requestMedia failed: ' . $e->getMessage(), 0, $e);
        }

        $length = $headers['content-length'][0] ?? null;
        $client = $this->httpClient;
        $chunks = (static function () use ($client, $response): \Generator {
            try {
                foreach ($client->stream($response) as $chunk) {
                    $content = $chunk->getContent();
                    if ($content !== '') {
                        yield $content;
                    }
                }
            } catch (HttpClientException $e) {
                throw new PhantasosException('requestMedia failed mid-transfer: ' . $e->getMessage(), 0, $e);
            }
        })();

        return new FileStream(
            $status,
            $headers['content-type'][0] ?? 'application/octet-stream',
            is_numeric($length) ? (int) $length : null,
            $headers['content-range'][0] ?? null,
            $chunks,
        );
    }

    /** @return array<string, string> keeps the key out of var_dump() and error pages */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl];
    }

    /** @param array<string, mixed> $options */
    private function send(string $method, string $path, array $options, string $what): ResponseInterface
    {
        $headers            = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $options['headers'] = ['X-Api-Key' => $this->apiKey] + $headers;
        // Never follow redirects: HttpClient would resend X-Api-Key to wherever they point.
        $options['max_redirects'] = 0;
        try {
            $response = $this->httpClient->request($method, $this->baseUrl . $path, $options);
            $response->getStatusCode(); // surface connection errors here, not later
            return $response;
        } catch (HttpClientException $e) {
            throw new PhantasosException($what . ' failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @return array<mixed> */
    private function decode(ResponseInterface $response): array
    {
        try {
            $data = json_decode($response->getContent(false), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException|HttpClientException $e) {
            throw new PhantasosException('Phantasos returned an unreadable response.', 0, $e);
        }
        if (!is_array($data)) {
            throw new PhantasosException('Phantasos returned an unreadable response.');
        }
        return $data;
    }

    private function error(ResponseInterface $response, string $what): PhantasosException
    {
        try {
            $status = $response->getStatusCode();
            $body   = trim($response->getContent(false));
        } catch (HttpClientException $e) {
            return new PhantasosException($what . ' failed: ' . $e->getMessage(), 0, $e);
        }

        return match ($status) {
            403     => new AuthenticationFailedException('Phantasos rejected the API key (check PHANTASOS_API_KEY).'),
            404     => new MediaNotFoundException($body !== '' ? $body : 'Media was not found'),
            default => new PhantasosException(sprintf('%s failed: HTTP %d %s', $what, $status, mb_substr($body, 0, 200))),
        };
    }
}
