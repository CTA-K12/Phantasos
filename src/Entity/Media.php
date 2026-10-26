<?php

namespace App\Entity;

use App\Enum\MediaStatus;
use App\Enum\MediaType;
use App\Repository\MediaRepository;
use App\Util\LegacyId;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One uploaded item. Created as an upload ticket (requestUploadTicket); becomes
 * `uploaded` when the browser posts the file, and `ready` once processing finishes.
 *
 * The ID is a 24-hex legacy ObjectId (see LegacyId), not a UUID: it is the public
 * `media_id` consumers already store, and it determines the on-disk directory.
 */
#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\Index(columns: ['client_id', 'created_at'], name: 'idx_media_client_created')]
#[ORM\Index(columns: ['status'], name: 'idx_media_status')]
class Media
{
    #[ORM\Id]
    #[ORM\Column(length: 24, options: ['fixed' => true])]
    private string $id;

    #[ORM\ManyToOne(targetEntity: ApiClient::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ApiClient $client;

    #[ORM\Column]
    private bool $hideToOthers = true;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $securityTags = [];

    #[ORM\Column]
    private bool $uploaded = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $uploadExpiration;

    #[ORM\Column(length: 20, nullable: true, enumType: MediaType::class)]
    private ?MediaType $mediaType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $callback = null;

    #[ORM\Column]
    private bool $callbackSent = false;

    #[ORM\Column]
    private bool $ready = false;

    #[ORM\Column]
    private float $processingPercentage = 0.0;

    #[ORM\Column(length: 20, enumType: MediaStatus::class)]
    private MediaStatus $status = MediaStatus::Queued;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, MediaFile> */
    #[ORM\OneToMany(targetEntity: MediaFile::class, mappedBy: 'media', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $files;

    public function __construct(ApiClient $client, \DateTimeImmutable $uploadExpiration, ?string $id = null)
    {
        $this->id               = $id ?? LegacyId::generate();
        $this->client           = $client;
        $this->uploadExpiration = $uploadExpiration;
        $this->createdAt        = new \DateTimeImmutable();
        $this->files            = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getClient(): ApiClient
    {
        return $this->client;
    }

    public function isOwnedBy(ApiClient $client): bool
    {
        return $this->client->getId()->equals($client->getId());
    }

    /** Relative directory under the media root, e.g. `abcde/fghij/klmno/<id>/`. */
    public function getStoragePath(): string
    {
        return LegacyId::storagePath($this->id);
    }

    public function isHideToOthers(): bool
    {
        return $this->hideToOthers;
    }

    public function setHideToOthers(bool $hideToOthers): static
    {
        $this->hideToOthers = $hideToOthers;
        return $this;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @param list<string> $tags */
    public function setTags(array $tags): static
    {
        $this->tags = $tags;
        return $this;
    }

    /** @return list<string> */
    public function getSecurityTags(): array
    {
        return $this->securityTags;
    }

    /** @param list<string> $securityTags */
    public function setSecurityTags(array $securityTags): static
    {
        $this->securityTags = $securityTags;
        return $this;
    }

    public function isUploaded(): bool
    {
        return $this->uploaded;
    }

    public function setUploaded(bool $uploaded): static
    {
        $this->uploaded = $uploaded;
        return $this;
    }

    public function getUploadExpiration(): \DateTimeImmutable
    {
        return $this->uploadExpiration;
    }

    public function getMediaType(): ?MediaType
    {
        return $this->mediaType;
    }

    public function setMediaType(?MediaType $mediaType): static
    {
        $this->mediaType = $mediaType;
        return $this;
    }

    public function getCallback(): ?string
    {
        return $this->callback;
    }

    public function setCallback(?string $callback): static
    {
        $this->callback = $callback;
        return $this;
    }

    public function isCallbackSent(): bool
    {
        return $this->callbackSent;
    }

    public function setCallbackSent(bool $callbackSent): static
    {
        $this->callbackSent = $callbackSent;
        return $this;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function setReady(bool $ready): static
    {
        $this->ready = $ready;
        return $this;
    }

    public function getProcessingPercentage(): float
    {
        return $this->processingPercentage;
    }

    public function setProcessingPercentage(float $processingPercentage): static
    {
        $this->processingPercentage = $processingPercentage;
        return $this;
    }

    public function getStatus(): MediaStatus
    {
        return $this->status;
    }

    public function setStatus(MediaStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /** @return Collection<int, MediaFile> */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    /** Appends a file after the existing ones; the original is always added first. */
    public function addFile(MediaFile $file): static
    {
        $file->setPosition($this->files->count());
        $this->files->add($file);
        return $this;
    }

    public function getOriginalFile(): ?MediaFile
    {
        foreach ($this->files as $file) {
            if ($file->isOriginal()) {
                return $file;
            }
        }
        return null;
    }
}
