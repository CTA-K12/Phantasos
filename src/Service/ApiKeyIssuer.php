<?php

namespace App\Service;

use App\Entity\ApiClient;

/**
 * Generates API keys. The plaintext is returned exactly once, for display to the
 * administrator; only its SHA-256 is stored.
 */
class ApiKeyIssuer
{
    /** 192 bits of randomness, hex-encoded (48 characters). */
    public static function generate(): string
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * Creates a client with a fresh key.
     *
     * @return array{ApiClient, string}
     */
    public function create(string $name): array
    {
        $key = self::generate();
        return [new ApiClient($name, $key), $key];
    }

    /** Replaces the client's key; the old key stops working as soon as this is flushed. */
    public function rotate(ApiClient $client): string
    {
        $key = self::generate();
        $client->setApiKey($key);
        return $key;
    }
}
