<?php

namespace App\Entity;

use App\Repository\ApiClientRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A consuming application (or one environment of one) holding an API key.
 *
 * Only the SHA-256 of the key is stored. Keys are high-entropy random strings, so an
 * unsalted hash is enough to make a database leak useless while keeping lookups indexed.
 */
#[ORM\Entity(repositoryClass: ApiClientRepository::class)]
class ApiClient implements UserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100, unique: true)]
    private string $name;

    /** applicationName in the legacy system, when it differs; used by the import. */
    #[ORM\Column(length: 100, unique: true, nullable: true)]
    private ?string $legacyName = null;

    #[ORM\Column(length: 64, unique: true, options: ['fixed' => true])]
    private string $keyHash;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(string $name, string $apiKey)
    {
        $this->id        = Uuid::v7();
        $this->name      = $name;
        $this->keyHash   = self::hashKey($apiKey);
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function hashKey(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Name reported as `owner` in mediaInfo. Imported clients keep their legacy name there
     * so renaming a client never changes what consumers see.
     */
    public function getPublicOwnerName(): string
    {
        return $this->legacyName ?? $this->name;
    }

    public function getLegacyName(): ?string
    {
        return $this->legacyName;
    }

    public function setLegacyName(?string $legacyName): static
    {
        $this->legacyName = $legacyName;
        return $this;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function setApiKey(string $apiKey): static
    {
        $this->keyHash = self::hashKey($apiKey);
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function setContactName(?string $contactName): static
    {
        $this->contactName = $contactName;
        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): static
    {
        $this->contactEmail = $contactEmail;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->name !== '' ? $this->name : 'unknown';
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_API_CLIENT'];
    }

    public function eraseCredentials(): void
    {
    }
}
