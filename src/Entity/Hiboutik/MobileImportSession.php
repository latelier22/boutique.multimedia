<?php

namespace App\Entity\Hiboutik;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="hib_mobile_import_session")
 */
class MobileImportSession
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * delivery_note | purchase_order
     *
     * @ORM\Column(type="string", length=32)
     */
    private string $sourceType = 'delivery_note';

    /**
     * Nom d'origine du fichier
     *
     * @ORM\Column(type="string", length=255)
     */
    private string $sourceFilename = '';

    /**
     * Nom stocké dans var/mobile-imports
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $storedFilename = null;

    /**
     * Code BL / code commande
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $documentCode = null;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $supplierId = null;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $hibInventoryInputId = null;

    /**
     * draft | prepared | importing | imported | error
     *
     * @ORM\Column(type="string", length=32)
     */
    private string $status = 'draft';

    /**
     * @ORM\Column(type="json")
     */
    private array $meta = [];

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, MobileImportRow>
     *
     * @ORM\OneToMany(
     *     targetEntity="App\Entity\Hiboutik\MobileImportRow",
     *     mappedBy="session",
     *     cascade={"persist","remove"},
     *     orphanRemoval=true
     * )
     * @ORM\OrderBy({"id" = "ASC"})
     */
    private Collection $rows;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->rows = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function setSourceType(string $sourceType): self
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    public function getSourceFilename(): string
    {
        return $this->sourceFilename;
    }

    public function setSourceFilename(string $sourceFilename): self
    {
        $this->sourceFilename = $sourceFilename;

        return $this;
    }

    public function getStoredFilename(): ?string
    {
        return $this->storedFilename;
    }

    public function setStoredFilename(?string $storedFilename): self
    {
        $this->storedFilename = $storedFilename;

        return $this;
    }

    public function getDocumentCode(): ?string
    {
        return $this->documentCode;
    }

    public function setDocumentCode(?string $documentCode): self
    {
        $this->documentCode = $documentCode ? trim($documentCode) : null;

        return $this;
    }

    public function getSupplierId(): ?int
    {
        return $this->supplierId;
    }

    public function setSupplierId(?int $supplierId): self
    {
        $this->supplierId = $supplierId;

        return $this;
    }

    public function getHibInventoryInputId(): ?int
    {
        return $this->hibInventoryInputId;
    }

    public function setHibInventoryInputId(?int $hibInventoryInputId): self
    {
        $this->hibInventoryInputId = $hibInventoryInputId;

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

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function setMeta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

/**
 * @ORM\Column(type="date_immutable", nullable=true)
 */
private ?\DateTimeImmutable $documentDate = null;



public function getDocumentDate(): ?\DateTimeImmutable
{
    return $this->documentDate;
}

public function setDocumentDate(?\DateTimeImmutable $documentDate): self
{
    $this->documentDate = $documentDate;

    return $this;
}



    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }




    /**
     * @return Collection<int, MobileImportRow>
     */
    public function getRows(): Collection
    {
        return $this->rows;
    }

    public function addRow(MobileImportRow $row): self
    {
        if (!$this->rows->contains($row)) {
            $this->rows->add($row);
            $row->setSession($this);
        }

        return $this;
    }

    public function removeRow(MobileImportRow $row): self
    {
        if ($this->rows->removeElement($row)) {
            if ($row->getSession() === $this) {
                $row->setSession(null);
            }
        }

        return $this;
    }
}