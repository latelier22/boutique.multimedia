<?php

namespace App\Entity\Hiboutik;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table(name="hib_label_board")
 */
class LabelBoard
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private string $name = '';

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $updatedAt;

    /**
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private bool $archived = false;

    /**
     * Dernier PDF généré
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $pdfFilename = null;

    /**
     * @ORM\Column(type="string", length=500, nullable=true)
     */
    private ?string $pdfPath = null;

    /**
     * @ORM\Column(type="string", length=500, nullable=true)
     */
    private ?string $pdfUrl = null;

    /**
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $pdfGeneratedAt = null;

    /**
     * @ORM\OneToMany(
     *     targetEntity="App\Entity\Hiboutik\LabelBoardItem",
     *     mappedBy="board",
     *     cascade={"persist", "remove"},
     *     orphanRemoval=true
     * )
     * @ORM\OrderBy({"slotIndex" = "ASC"})
     */
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): self
    {
        $this->archived = $archived;
        return $this;
    }

    public function getPdfFilename(): ?string
    {
        return $this->pdfFilename;
    }

    public function setPdfFilename(?string $pdfFilename): self
    {
        $this->pdfFilename = $pdfFilename;
        return $this;
    }

    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(?string $pdfPath): self
    {
        $this->pdfPath = $pdfPath;
        return $this;
    }

    public function getPdfUrl(): ?string
    {
        return $this->pdfUrl;
    }

    public function setPdfUrl(?string $pdfUrl): self
    {
        $this->pdfUrl = $pdfUrl;
        return $this;
    }

    public function getPdfGeneratedAt(): ?\DateTimeImmutable
    {
        return $this->pdfGeneratedAt;
    }

    public function setPdfGeneratedAt(?\DateTimeImmutable $pdfGeneratedAt): self
    {
        $this->pdfGeneratedAt = $pdfGeneratedAt;
        return $this;
    }

    /**
     * @return Collection|LabelBoardItem[]
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(LabelBoardItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items[] = $item;
            $item->setBoard($this);
        }

        return $this;
    }

    public function removeItem(LabelBoardItem $item): self
    {
        if ($this->items->removeElement($item)) {
            if ($item->getBoard() === $this) {
                $item->setBoard(null);
            }
        }

        return $this;
    }
}