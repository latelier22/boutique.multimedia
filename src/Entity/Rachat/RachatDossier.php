<?php

namespace App\Entity\Rachat;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Stock\Boite;

/**
 * @ORM\Entity()
 * @ORM\Table(name="rachat_dossiers")
 * @ORM\HasLifecycleCallbacks()
 */
class RachatDossier
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_READY = 'ready';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_LOCKED = 'locked';

    public const CUSTOMER_UNRESOLVED = 'unresolved';
    public const CUSTOMER_MATCHED = 'matched';
    public const CUSTOMER_CREATED = 'created';
    public const CUSTOMER_VIRTUAL = 'virtual';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * Référence lisible du dossier (ex: RA-20260404-001)
     * @ORM\Column(name="reference", type="string", length=64, nullable=true, unique=true)
     */
    private ?string $reference = null;

    /**
     * draft / ready / signed / cancelled
     * @ORM\Column(name="status", type="string", length=32, options={"default":"draft"})
     */
    private string $status = self::STATUS_DRAFT;

    /**
     * Permet de rattacher ce dossier à un ancien rachat si conversion legacy -> V2
     * @ORM\Column(name="legacy_rachat_id", type="integer", nullable=true)
     */
    private ?int $legacyRachatId = null;

    /**
     * ID client Hiboutik
     * @ORM\Column(name="hib_customer_id", type="integer", nullable=true)
     */
    private ?int $hibCustomerId = null;

    /**
     * Snapshot des infos client au moment de la signature
     * @ORM\Column(name="nom_snapshot", type="string", length=255, nullable=true)
     */
    private ?string $nomSnapshot = null;

    /**
     * @ORM\Column(name="prenom_snapshot", type="string", length=255, nullable=true)
     */
    private ?string $prenomSnapshot = null;

    /**
     * @ORM\Column(name="telephone_snapshot", type="string", length=255, nullable=true)
     */
    private ?string $telephoneSnapshot = null;

    /**
     * @ORM\Column(name="email_snapshot", type="string", length=255, nullable=true)
     */
    private ?string $emailSnapshot = null;

    /**
     * @ORM\Column(name="adresse_snapshot", type="text", nullable=true)
     */
    private ?string $adresseSnapshot = null;

    /**
     * @ORM\Column(name="code_postal_snapshot", type="string", length=64, nullable=true)
     */
    private ?string $codePostalSnapshot = null;

    /**
     * @ORM\Column(name="ville_snapshot", type="string", length=255, nullable=true)
     */
    private ?string $villeSnapshot = null;

    /**
     * Numéro de pièce d'identité côté dossier
     * @ORM\Column(name="numero_ci_snapshot", type="string", length=255, nullable=true)
     */
    private ?string $numeroCiSnapshot = null;

    /**
     * URL / JSON recto-verso / chemin selon ton système actuel
     * @ORM\Column(name="piece_identite_url", type="text", nullable=true)
     */
    private ?string $pieceIdentiteUrl = null;

    /**
     * Signature client
     * @ORM\Column(name="signature_url", type="text", nullable=true)
     */
    private ?string $signatureUrl = null;

    /**
     * PDF figé du dossier signé
     * @ORM\Column(name="pdf_url", type="text", nullable=true)
     */
    private ?string $pdfUrl = null;

    /**
     * Date juridique / commerciale de cession
     * @ORM\Column(name="date_cession", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $dateCession = null;

    /**
     * Total du dossier. On utilise decimal pour éviter les erreurs float.
     * Doctrine retourne généralement une string.
     * @ORM\Column(name="total_achat", type="decimal", precision=10, scale=2, nullable=true)
     */
    private ?string $totalAchat = null;

    /**
     * cash / cb / virement / avoir / etc.
     * @ORM\Column(name="paid_method", type="string", length=32, nullable=true)
     */
    private ?string $paidMethod = null;





    /**
     * @ORM\Column(name="paid_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $paidAt = null;

    /**
     * Date de signature
     * @ORM\Column(name="signed_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $signedAt = null;

    /**
     * Date de verrouillage. Peut être identique à signedAt.
     * @ORM\Column(name="locked_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $lockedAt = null;

    /**
     * Date d’annulation éventuelle
     * @ORM\Column(name="cancelled_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $cancelledAt = null;

    /**
     * Motif d’annulation
     * @ORM\Column(name="cancel_reason", type="text", nullable=true)
     */
    private ?string $cancelReason = null;

    /**
     * Notes internes non visibles sur le document signé
     * @ORM\Column(name="notes", type="text", nullable=true)
     */
    private ?string $notes = null;

    /**
     * Pour archivage logique éventuel
     * @ORM\Column(name="enabled", type="boolean", options={"default":1})
     */
    private bool $enabled = true;

    /**
     * Lien revendeur si ton flux en a encore besoin
     * @ORM\ManyToOne(targetEntity=Revendeur::class)
     * @ORM\JoinColumn(name="revendeur_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private ?Revendeur $revendeur = null;

    /**
     * Lien boîte si ton flux stock en a besoin
     * @ORM\OneToOne(targetEntity=Boite::class)
     * @ORM\JoinColumn(name="boite_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private ?Boite $boite = null;

    /**
     * Si tu gardes un lien avec les flux Hiboutik d’arrivage/entrée
     * @ORM\Column(name="hib_inventory_input_id", type="integer", nullable=true)
     */
    private ?int $hibInventoryInputId = null;

    /**
     * @ORM\Column(name="hib_arrivage_added_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $hibArrivageAddedAt = null;

    /**
     * @ORM\Column(name="created_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * @ORM\Column(name="updated_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $updatedAt = null;



    /** @ORM\Column(name="customer_link_status", type="string", length=32, nullable=true) */
private ?string $customerLinkStatus = null;

/** @ORM\Column(name="customer_link_note", type="text", nullable=true) */
private ?string $customerLinkNote = null;

public function getCustomerLinkStatus(): ?string
{
    return $this->customerLinkStatus;
}

public function setCustomerLinkStatus(?string $customerLinkStatus): self
{
    $this->customerLinkStatus = $customerLinkStatus;
    return $this;
}

public function getCustomerLinkNote(): ?string
{
    return $this->customerLinkNote;
}

public function setCustomerLinkNote(?string $customerLinkNote): self
{
    $this->customerLinkNote = $customerLinkNote;
    return $this;
}


    /**
     * Les produits repris dans le dossier
     * La classe RachatItem sera créée ensuite.
     *
     * @ORM\OneToMany(
     *     targetEntity=RachatItem::class,
     *     mappedBy="dossier",
     *     cascade={"persist", "remove"},
     *     orphanRemoval=true
     * )
     * @ORM\OrderBy({"ordre" = "ASC", "id" = "ASC"})
     */
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    /** @ORM\PrePersist */
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();

        if ($this->createdAt === null) {
            $this->createdAt = $now;
        }

        if ($this->updatedAt === null) {
            $this->updatedAt = $now;
        }
    }

    /** @ORM\PreUpdate */
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isLocked(): bool
    {
        return $this->lockedAt !== null || $this->status === self::STATUS_SIGNED;
    }

    public function isSigned(): bool
    {
        return $this->signedAt !== null || $this->status === self::STATUS_SIGNED;
    }

    public function isCancelled(): bool
    {
        return $this->cancelledAt !== null || $this->status === self::STATUS_CANCELLED;
    }

    public function canBeEdited(): bool
    {
        return !$this->isLocked() && !$this->isCancelled();
    }

    public function sign(): self
    {
        $now = new \DateTimeImmutable();

        $this->status = self::STATUS_SIGNED;
        $this->signedAt = $this->signedAt ?? $now;
        $this->lockedAt = $this->lockedAt ?? $now;

        return $this;
    }

    public function cancel(?string $reason = null): self
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancelledAt = new \DateTimeImmutable();
        $this->cancelReason = $reason;

        return $this;
    }

    public function unlockForAdminOnly(): self
    {
        $this->lockedAt = null;

        if ($this->status === self::STATUS_SIGNED) {
            $this->status = self::STATUS_DRAFT;
        }

        return $this;
    }

    // ===== Getters / Setters =====

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;
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

    public function getLegacyRachatId(): ?int
    {
        return $this->legacyRachatId;
    }

    public function setLegacyRachatId(?int $legacyRachatId): self
    {
        $this->legacyRachatId = $legacyRachatId;
        return $this;
    }

    public function getHibCustomerId(): ?int
    {
        return $this->hibCustomerId;
    }

    public function setHibCustomerId(?int $hibCustomerId): self
    {
        $this->hibCustomerId = $hibCustomerId;
        return $this;
    }

    public function getNomSnapshot(): ?string
    {
        return $this->nomSnapshot;
    }

    public function setNomSnapshot(?string $nomSnapshot): self
    {
        $this->nomSnapshot = $nomSnapshot;
        return $this;
    }

    public function getPrenomSnapshot(): ?string
    {
        return $this->prenomSnapshot;
    }

    public function setPrenomSnapshot(?string $prenomSnapshot): self
    {
        $this->prenomSnapshot = $prenomSnapshot;
        return $this;
    }

    public function getTelephoneSnapshot(): ?string
    {
        return $this->telephoneSnapshot;
    }

    public function setTelephoneSnapshot(?string $telephoneSnapshot): self
    {
        $this->telephoneSnapshot = $telephoneSnapshot;
        return $this;
    }

    public function getEmailSnapshot(): ?string
    {
        return $this->emailSnapshot;
    }

    public function setEmailSnapshot(?string $emailSnapshot): self
    {
        $this->emailSnapshot = $emailSnapshot;
        return $this;
    }

    public function getAdresseSnapshot(): ?string
    {
        return $this->adresseSnapshot;
    }

    public function setAdresseSnapshot(?string $adresseSnapshot): self
    {
        $this->adresseSnapshot = $adresseSnapshot;
        return $this;
    }

    public function getCodePostalSnapshot(): ?string
    {
        return $this->codePostalSnapshot;
    }

    public function setCodePostalSnapshot(?string $codePostalSnapshot): self
    {
        $this->codePostalSnapshot = $codePostalSnapshot;
        return $this;
    }

    public function getVilleSnapshot(): ?string
    {
        return $this->villeSnapshot;
    }

    public function setVilleSnapshot(?string $villeSnapshot): self
    {
        $this->villeSnapshot = $villeSnapshot;
        return $this;
    }

    public function getNumeroCiSnapshot(): ?string
    {
        return $this->numeroCiSnapshot;
    }

    public function setNumeroCiSnapshot(?string $numeroCiSnapshot): self
    {
        $this->numeroCiSnapshot = $numeroCiSnapshot;
        return $this;
    }

    public function getPieceIdentiteUrl(): ?string
    {
        return $this->pieceIdentiteUrl;
    }

    public function setPieceIdentiteUrl(?string $pieceIdentiteUrl): self
    {
        $this->pieceIdentiteUrl = $pieceIdentiteUrl;
        return $this;
    }

    public function getSignatureUrl(): ?string
    {
        return $this->signatureUrl;
    }

    public function setSignatureUrl(?string $signatureUrl): self
    {
        $this->signatureUrl = $signatureUrl;
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

    public function getDateCession(): ?\DateTimeImmutable
    {
        return $this->dateCession;
    }

    public function setDateCession(?\DateTimeImmutable $dateCession): self
    {
        $this->dateCession = $dateCession;
        return $this;
    }

    public function getTotalAchat(): ?string
    {
        return $this->totalAchat;
    }

    public function setTotalAchat(?string $totalAchat): self
    {
        $this->totalAchat = $totalAchat;
        return $this;
    }

    public function getPaidMethod(): ?string
    {
        return $this->paidMethod;
    }

    public function setPaidMethod(?string $paidMethod): self
    {
        $this->paidMethod = $paidMethod;
        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): self
    {
        $this->paidAt = $paidAt;
        return $this;
    }

    public function getSignedAt(): ?\DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function setSignedAt(?\DateTimeImmutable $signedAt): self
    {
        $this->signedAt = $signedAt;
        return $this;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function setLockedAt(?\DateTimeImmutable $lockedAt): self
    {
        $this->lockedAt = $lockedAt;
        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): self
    {
        $this->cancelledAt = $cancelledAt;
        return $this;
    }

    public function getCancelReason(): ?string
    {
        return $this->cancelReason;
    }

    public function setCancelReason(?string $cancelReason): self
    {
        $this->cancelReason = $cancelReason;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getRevendeur(): ?Revendeur
    {
        return $this->revendeur;
    }

    public function setRevendeur(?Revendeur $revendeur): self
    {
        $this->revendeur = $revendeur;
        return $this;
    }

    public function getBoite(): ?Boite
    {
        return $this->boite;
    }

    public function setBoite(?Boite $boite): self
    {
        $this->boite = $boite;
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

    public function getHibArrivageAddedAt(): ?\DateTimeImmutable
    {
        return $this->hibArrivageAddedAt;
    }

    public function setHibArrivageAddedAt(?\DateTimeImmutable $hibArrivageAddedAt): self
    {
        $this->hibArrivageAddedAt = $hibArrivageAddedAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, RachatItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(RachatItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items[] = $item;
            $item->setDossier($this);
        }

        return $this;
    }

    public function removeItem(RachatItem $item): self
    {
        if ($this->items->removeElement($item)) {
            if ($item->getDossier() === $this) {
                $item->setDossier(null);
            }
        }

        return $this;
    }


public function lock(): self
{
    $this->status = self::STATUS_LOCKED;
    $this->lockedAt = new \DateTimeImmutable();

    return $this;
}
}