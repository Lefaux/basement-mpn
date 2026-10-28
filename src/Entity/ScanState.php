<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'scan_state')]
class ScanState
{
    #[ORM\Id]
    #[ORM\Column(length: 128)]
    private string $driveId;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $changeToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    public function __construct(string $driveId) { $this->driveId = $driveId; }
    public function getChangeToken(): ?string { return $this->changeToken; }
    public function getLastAttemptAt(): ?\DateTimeImmutable { return $this->lastAttemptAt; }
    public function getLastError(): ?string { return $this->lastError; }
    public function finish(?string $token): void { $this->changeToken = $token; $this->lastAttemptAt = new \DateTimeImmutable(); $this->lastError = null; }
    public function fail(string $error): void { $this->lastAttemptAt = new \DateTimeImmutable(); $this->lastError = $error; }
}
