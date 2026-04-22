<?php

namespace App\Entity\Hiboutik;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="hib_invoice_pointing_session")
 */
class InvoicePointingSession
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(name="id", type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(name="source_filename", type="string", length=255, nullable=true)
     */
    private ?string $sourceFilename = null;

    /**
     * @ORM\Column(name="stored_filename", type="string", length=255, nullable=true)
     */
    private ?string $storedFilename = null;

    /**
     * @ORM\Column(name="status", type="string", length=32)
     */
    private string $status = 'draft';

    /**
     * @ORM\Column(name="meta", type="json")
     */
    private array $meta = [];

    /**
 * @ORM\Column(name="supplier_name", type="string", length=255, nullable=true)
 */
private ?string $supplierName = null;

public function getSupplierName(): ?string
{
    return $this->supplierName;
}

public function setSupplierName(?string $supplierName): self
{
    $this->supplierName = $supplierName ? trim($supplierName) : null;
    return $this;
}

    /**
     * @ORM\Column(name="created_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\Column(name="updated_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @ORM\OneToMany(
     *     targetEntity="App\Entity\Hiboutik\InvoicePointingRow",
     *     mappedBy="session",
     *     orphanRemoval=true,
     *     cascade={"persist", "remove"}
     * )
     * @ORM\OrderBy({"lineNumber"="ASC", "id"="ASC"})
     */
    private Collection $rows;

    public function __construct()
    {
        $this->rows = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSourceFilename(): ?string { return $this->sourceFilename; }
    public function setSourceFilename(?string $sourceFilename): self
    {
        $this->sourceFilename = $sourceFilename ? trim($sourceFilename) : null;
        return $this;
    }

    public function getStoredFilename(): ?string { return $this->storedFilename; }
    public function setStoredFilename(?string $storedFilename): self
    {
        $this->storedFilename = $storedFilename ? trim($storedFilename) : null;
        return $this;
    }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self
    {
        $this->status = trim($status) !== '' ? trim($status) : 'draft';
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getMeta(): array { return $this->meta; }
    public function setMeta(array $meta): self
    {
        $this->meta = $meta;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getRows(): Collection { return $this->rows; }

    public function addRow(InvoicePointingRow $row): self
    {
        if (!$this->rows->contains($row)) {
            $this->rows[] = $row;
            $row->setSession($this);
        }

        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function removeRow(InvoicePointingRow $row): self
    {
        if ($this->rows->removeElement($row)) {
            if ($row->getSession() === $this) {
                $row->setSession(null);
            }
        }

        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}