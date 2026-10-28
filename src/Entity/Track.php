<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tracks')]
class Track
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128, unique: true)]
    private string $driveId;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $parentDriveId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $artist = null;

    #[ORM\Column(length: 255)]
    private string $folderName;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $path;

    #[ORM\Column(length: 40)]
    private string $stage = 'idea';

    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    #[ORM\Column(type: Types::JSON)]
    private array $issues = [];

    #[ORM\Column(type: Types::JSON)]
    private array $files = [];

    #[ORM\Column]
    private bool $cleanLayout = false;

    #[ORM\Column]
    private bool $archived = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $driveModifiedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $driveId, string $folderName, string $path)
    {
        $this->driveId = $driveId;
        $this->folderName = $folderName;
        $this->title = $folderName;
        $this->path = $path;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getDriveId(): string { return $this->driveId; }
    public function getParentDriveId(): ?string { return $this->parentDriveId; }
    public function getArtist(): ?string { return $this->artist; }
    public function getFolderName(): string { return $this->folderName; }
    public function getTitle(): string { return $this->title; }
    public function getPath(): string { return $this->path; }
    public function getStage(): string { return $this->stage; }
    public function getTags(): array { return $this->tags; }
    public function getIssues(): array { return $this->issues; }
    public function getFiles(): array { return $this->files; }
    public function isCleanLayout(): bool { return $this->cleanLayout; }
    public function isArchived(): bool { return $this->archived; }
    public function getDriveModifiedAt(): ?\DateTimeImmutable { return $this->driveModifiedAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function refresh(array $data): void
    {
        foreach ($data as $property => $value) {
            if (property_exists($this, $property)) {
                $this->$property = $value;
            }
        }
        $this->archived = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function archive(): void
    {
        $this->archived = true;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
