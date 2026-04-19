<?php

namespace App\Entity\Hiboutik;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="hib_incoming_supplier_document")
 */
class IncomingSupplierDocument
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $messageId = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $fromEmail = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $fromName = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $subject = null;

    /**
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $receivedAt = null;

    /**
     * new | parsed | ignored | error
     *
     * @ORM\Column(type="string", length=32)
     */
    private string $status = 'new';

    /**
     * invoice | delivery_note | purchase_order | unknown
     *
     * @ORM\Column(type="string", length=32, nullable=true)
     */
    private ?string $detectedType = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $detectedDocumentCode = null;

    /**
     * @ORM\Column(type="date_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $detectedDocumentDate = null;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $hasImei = false;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $detectedSupplierId = null;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $importSessionId = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $notes = null;

    /**
     * @ORM\Column(type="json")
     */
    private array $meta = [];


/**
 * @ORM\Column(type="string", length=10, nullable=true)
 */
private ?string $documentType = null; // BC, FA, BL, AV

/**
 * @ORM\Column(type="string", length=255, nullable=true)
 */
private ?string $documentNumber = null;

/**
 * @ORM\Column(type="date_immutable", nullable=true)
 */
private ?\DateTimeImmutable $documentDate = null;

/**
 * @ORM\Column(type="string", length=255, nullable=true)
 */
private ?string $supplierName = null;

/**
 * @ORM\Column(type="string", length=32)
 */
private string $processingStatus = 'new';



public function getDocumentType(): ?string
{
    return $this->documentType;
}

public function setDocumentType(?string $documentType): self
{
    $this->documentType = $documentType;

    return $this;
}

public function getDocumentNumber(): ?string
{
    return $this->documentNumber;
}

public function setDocumentNumber(?string $documentNumber): self
{
    $this->documentNumber = $documentNumber;

    return $this;
}

public function getDocumentDate(): ?\DateTimeImmutable
{
    return $this->documentDate;
}

public function setDocumentDate(?\DateTimeImmutable $documentDate): self
{
    $this->documentDate = $documentDate;

    return $this;
}

public function getSupplierName(): ?string
{
    return $this->supplierName;
}

public function setSupplierName(?string $supplierName): self
{
    $this->supplierName = $supplierName;

    return $this;
}

public function getProcessingStatus(): string
{
    return $this->processingStatus;
}

public function setProcessingStatus(string $processingStatus): self
{
    $this->processingStatus = $processingStatus;

    return $this;
}

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, IncomingSupplierAttachment>
     *
     * @ORM\OneToMany(
     *     targetEntity="App\Entity\Hiboutik\IncomingSupplierAttachment",
     *     mappedBy="document",
     *     cascade={"persist","remove"},
     *     orphanRemoval=true
     * )
     * @ORM\OrderBy({"id" = "ASC"})
     */
    private Collection $attachments;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->attachments = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getMessageId(): ?string { return $this->messageId; }
    public function setMessageId(?string $messageId): self { $this->messageId = $messageId; return $this; }

    public function getFromEmail(): ?string { return $this->fromEmail; }
    public function setFromEmail(?string $fromEmail): self { $this->fromEmail = $fromEmail; return $this; }

    public function getFromName(): ?string { return $this->fromName; }
    public function setFromName(?string $fromName): self { $this->fromName = $fromName; return $this; }

    public function getSubject(): ?string { return $this->subject; }
    public function setSubject(?string $subject): self { $this->subject = $subject; return $this; }

    public function getReceivedAt(): ?\DateTimeImmutable { return $this->receivedAt; }
    public function setReceivedAt(?\DateTimeImmutable $receivedAt): self { $this->receivedAt = $receivedAt; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getDetectedType(): ?string { return $this->detectedType; }
    public function setDetectedType(?string $detectedType): self { $this->detectedType = $detectedType; return $this; }

    public function getDetectedDocumentCode(): ?string { return $this->detectedDocumentCode; }
    public function setDetectedDocumentCode(?string $detectedDocumentCode): self { $this->detectedDocumentCode = $detectedDocumentCode; return $this; }

    public function getDetectedDocumentDate(): ?\DateTimeImmutable { return $this->detectedDocumentDate; }
    public function setDetectedDocumentDate(?\DateTimeImmutable $detectedDocumentDate): self { $this->detectedDocumentDate = $detectedDocumentDate; return $this; }

    public function hasImei(): bool { return $this->hasImei; }
    public function setHasImei(bool $hasImei): self { $this->hasImei = $hasImei; return $this; }

    public function getDetectedSupplierId(): ?int { return $this->detectedSupplierId; }
    public function setDetectedSupplierId(?int $detectedSupplierId): self { $this->detectedSupplierId = $detectedSupplierId; return $this; }

    public function getImportSessionId(): ?int { return $this->importSessionId; }
    public function setImportSessionId(?int $importSessionId): self { $this->importSessionId = $importSessionId; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }

    public function getMeta(): array { return $this->meta; }
    public function setMeta(array $meta): self { $this->meta = $meta; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /**
     * @return Collection<int, IncomingSupplierAttachment>
     */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(IncomingSupplierAttachment $attachment): self
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            $attachment->setDocument($this);
        }

        return $this;
    }

    public function removeAttachment(IncomingSupplierAttachment $attachment): self
    {
        if ($this->attachments->removeElement($attachment)) {
            if ($attachment->getDocument() === $this) {
                $attachment->setDocument(null);
            }
        }

        return $this;
    }
}