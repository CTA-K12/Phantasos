<?php

namespace App\Entity;

use App\Repository\MediaFileRepository;
use App\Util\LegacyId;
use Doctrine\ORM\Mapping as ORM;

/**
 * One stored file of a Media item: the original upload or a processed rendition
 * (thumbnail, mp4, html…). Lives at `<media storage path>/<fileName>`.
 *
 * `position` preserves the legacy file order: mediaInfo lists files in this order and
 * consumers rely on it (e.g. `files[0]`).
 */
#[ORM\Entity(repositoryClass: MediaFileRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_media_file_position', columns: ['media_id', 'position'])]
class MediaFile
{
    #[ORM\Id]
    #[ORM\Column(length: 24, options: ['fixed' => true])]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Media::class, inversedBy: 'files')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Media $media;

    #[ORM\Column(type: 'smallint')]
    private int $position = 0;

    #[ORM\Column(length: 255)]
    private string $fileName;

    #[ORM\Column]
    private bool $original = false;

    #[ORM\Column(length: 255)]
    private string $contentType;

    /** Stored as text: legacy values are strings such as "128k". */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $bitRate = null;

    #[ORM\Column(nullable: true)]
    private ?int $width = null;

    #[ORM\Column(nullable: true)]
    private ?int $height = null;

    public function __construct(Media $media, string $fileName, string $contentType, ?string $id = null)
    {
        $this->id          = $id ?? LegacyId::generate();
        $this->media       = $media;
        $this->fileName    = $fileName;
        $this->contentType = $contentType;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getMedia(): Media
    {
        return $this->media;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;
        return $this;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    /** Path relative to the media root. */
    public function getStoragePath(): string
    {
        return $this->media->getStoragePath() . $this->fileName;
    }

    public function isOriginal(): bool
    {
        return $this->original;
    }

    public function setOriginal(bool $original): static
    {
        $this->original = $original;
        return $this;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getBitRate(): ?string
    {
        return $this->bitRate;
    }

    public function setBitRate(?string $bitRate): static
    {
        $this->bitRate = $bitRate;
        return $this;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function setWidth(?int $width): static
    {
        $this->width = $width;
        return $this;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setHeight(?int $height): static
    {
        $this->height = $height;
        return $this;
    }
}
