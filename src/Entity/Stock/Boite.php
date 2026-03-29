<?php

namespace App\Entity\Stock;

use App\Entity\Intervention\Intervention;
use App\Entity\Rachat\Rachat;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\Stock\BoiteRepository")
 * @ORM\Table(name="app_boite")
 */
class Boite
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_OCCUPIED = 'occupied';
    public const STATUS_INACTIVE = 'inactive';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=20, unique=true)
     */
    private string $code;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $status = self::STATUS_AVAILABLE;

    /**
     * @ORM\OneToOne(targetEntity=Intervention::class, mappedBy="boite")
     */
    private ?Intervention $intervention = null;

    /**
     * @ORM\OneToOne(targetEntity=Rachat::class, mappedBy="boite")
     */
    private ?Rachat $rachat = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $notes = null;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->status = self::STATUS_AVAILABLE;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = strtoupper(trim($code));
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE
            && $this->intervention === null
            && $this->rachat === null;
    }

    public function getIntervention(): ?Intervention
    {
        return $this->intervention;
    }

    public function setIntervention(?Intervention $intervention): self
    {
        $this->intervention = $intervention;
        return $this;
    }

    public function getRachat(): ?Rachat
    {
        return $this->rachat;
    }

    public function setRachat(?Rachat $rachat): self
    {
        $this->rachat = $rachat;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes !== null ? trim($notes) : null;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}