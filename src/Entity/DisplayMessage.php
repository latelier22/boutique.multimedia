<?php

namespace App\Entity;

use App\Repository\DisplayMessageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=DisplayMessageRepository::class)
 * @ORM\Table(name="display_message")
 * @ORM\HasLifecycleCallbacks()
 */
class DisplayMessage
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * Nom interne du message
     *
     * @ORM\Column(type="string", length=120)
     */
    private string $name = '';

    /**
     * Emplacement / slot : vitrine-droite, vitrine-gauche, tablette...
     *
     * @ORM\Column(type="string", length=80)
     */
    private string $slot = 'vitrine-droite';

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $isEnabled = true;

    /**
     * promo / announcement / warning / event
     *
     * @ORM\Column(type="string", length=40)
     */
    private string $template = 'announcement';

    /**
     * Petite accroche / badge
     *
     * @ORM\Column(type="string", length=120, nullable=true)
     */
    private ?string $badge = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $title = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $line1 = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $line2 = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $line3 = null;

    /**
     * Ligne de bas / date / pied de page
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $footerText = null;

    /**
     * black / orange / gradient / image
     *
     * @ORM\Column(type="string", length=40)
     */
    private string $backgroundType = 'black';

    /**
     * center / left
     *
     * @ORM\Column(type="string", length=20)
     */
    private string $textAlign = 'center';

    /**
     * Couleur accent principale
     *
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private ?string $accentColor = '#f59e0b';

    /**
     * Afficher toutes les X secondes
     *
     * @ORM\Column(type="integer")
     */
    private int $intervalSeconds = 15;

    /**
     * Afficher pendant X secondes
     *
     * @ORM\Column(type="integer")
     */
    private int $durationSeconds = 4;

    /**
     * Ordre d'affichage
     *
     * @ORM\Column(type="integer")
     */
    private int $sortOrder = 0;

    /**
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $startsAt = null;

    /**
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $endsAt = null;

    /**
     * Chemin éventuel d'image de fond
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $imagePath = null;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @ORM\PrePersist()
     */
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();

        if ($this->createdAt === null) {
            $this->createdAt = $now;
        }

        $this->updatedAt = $now;
    }

    /**
     * @ORM\PreUpdate()
     */
    public function onPreUpdate(): void
    {
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

    public function getSlot(): string
    {
        return $this->slot;
    }

    public function setSlot(string $slot): self
    {
        $this->slot = $slot;
        return $this;
    }

    public function getIsEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): self
    {
        $this->isEnabled = $isEnabled;
        return $this;
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function setTemplate(string $template): self
    {
        $this->template = $template;
        return $this;
    }

    public function getBadge(): ?string
    {
        return $this->badge;
    }

    public function setBadge(?string $badge): self
    {
        $this->badge = $badge;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getLine1(): ?string
    {
        return $this->line1;
    }

    public function setLine1(?string $line1): self
    {
        $this->line1 = $line1;
        return $this;
    }

    public function getLine2(): ?string
    {
        return $this->line2;
    }

    public function setLine2(?string $line2): self
    {
        $this->line2 = $line2;
        return $this;
    }

    public function getLine3(): ?string
    {
        return $this->line3;
    }

    public function setLine3(?string $line3): self
    {
        $this->line3 = $line3;
        return $this;
    }

    public function getFooterText(): ?string
    {
        return $this->footerText;
    }

    public function setFooterText(?string $footerText): self
    {
        $this->footerText = $footerText;
        return $this;
    }

    public function getBackgroundType(): string
    {
        return $this->backgroundType;
    }

    public function setBackgroundType(string $backgroundType): self
    {
        $this->backgroundType = $backgroundType;
        return $this;
    }

    public function getTextAlign(): string
    {
        return $this->textAlign;
    }

    public function setTextAlign(string $textAlign): self
    {
        $this->textAlign = $textAlign;
        return $this;
    }

    public function getAccentColor(): ?string
    {
        return $this->accentColor;
    }

    public function setAccentColor(?string $accentColor): self
    {
        $this->accentColor = $accentColor;
        return $this;
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function setIntervalSeconds(int $intervalSeconds): self
    {
        $this->intervalSeconds = $intervalSeconds;
        return $this;
    }

    public function getDurationSeconds(): int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(int $durationSeconds): self
    {
        $this->durationSeconds = $durationSeconds;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): self
    {
        $this->startsAt = $startsAt;
        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): self
    {
        $this->endsAt = $endsAt;
        return $this;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): self
    {
        $this->imagePath = $imagePath;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}