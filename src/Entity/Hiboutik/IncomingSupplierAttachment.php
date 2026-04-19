<?php

namespace App\Entity\Hiboutik;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="hib_incoming_supplier_attachment")
 */
class IncomingSupplierAttachment
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Hiboutik\IncomingSupplierDocument", inversedBy="attachments")
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private ?IncomingSupplierDocument $document = null;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private string $originalFilename = '';

    /**
     * @ORM\Column(type="string", length=255)
     */
    private string $storedFilename = '';

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private ?string $mimeType = null;

    /**
     * @ORM\Column(type="integer")
     */
    private int $size = 0;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $isParsable = false;

    /**
     * @ORM\Column(type="string", length=32)
     */
    private string $status = 'new';

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $notes = null;

    public function getId(): ?int { return $this->id; }

    public function getDocument(): ?IncomingSupplierDocument { return $this->document; }
    public function setDocument(?IncomingSupplierDocument $document): self { $this->document = $document; return $this; }

    public function getOriginalFilename(): string { return $this->originalFilename; }
    public function setOriginalFilename(string $originalFilename): self { $this->originalFilename = $originalFilename; return $this; }

    public function getStoredFilename(): string { return $this->storedFilename; }
    public function setStoredFilename(string $storedFilename): self { $this->storedFilename = $storedFilename; return $this; }

    public function getMimeType(): ?string { return $this->mimeType; }
    public function setMimeType(?string $mimeType): self { $this->mimeType = $mimeType; return $this; }

    public function getSize(): int { return $this->size; }
    public function setSize(int $size): self { $this->size = $size; return $this; }

    public function isParsable(): bool { return $this->isParsable; }
    public function setIsParsable(bool $isParsable): self { $this->isParsable = $isParsable; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
}