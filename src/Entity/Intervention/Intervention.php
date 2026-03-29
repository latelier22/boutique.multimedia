<?php

namespace App\Entity\Intervention;

use Doctrine\ORM\Mapping as ORM;
use App\Entity\Stock\Boite;


/**
 * @ORM\Entity
 * @ORM\Table(
 *     name="app_intervention",
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="uniq_intervention_number", columns={"intervention_number"})
 *     }
 * )
 */
class Intervention
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(name="intervention_number", type="integer", unique=true)
     */
    private ?int $interventionNumber = null;

    /**
     * @ORM\Column(type="string", length=30)
     */
    private string $status = 'open';

    /**
     * @ORM\Column(type="string", length=180)
     */
    private string $customerLastName = '';

    /**
     * @ORM\Column(type="string", length=180)
     */
    private string $customerFirstName = '';

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private ?string $customerPhone = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $deviceLabel = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $workToDo = null;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $hiboutikCustomerId = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $unlockPayloadEncrypted = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $unlockSummary = null;

    /**
     * @ORM\Column(type="string", length=64, nullable=true)
     */
    private ?string $tabletToken = null;

    /**
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $tabletTokenExpiresAt = null;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $updatedAt;

    /**
     * @ORM\OneToOne(targetEntity=Boite::class, inversedBy="intervention")
     * @ORM\JoinColumn(name="boite_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private ?Boite $boite = null;

    public function getBoite(): ?Boite
    {
        return $this->boite;
    }

    public function setBoite(?Boite $boite): self
    {
        $this->boite = $boite;
        return $this;
    }
    
// --- RECENTLY EDITED FIELDS BELOW (DO NOT SUGGEST DELETED FIELDS) ---
/**
 * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
 */
private ?string $amountTotal = null;

/**
 * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
 */
private ?string $amountPaid = null;

/**
 * @ORM\Column(type="string", length=50, nullable=true)
 */
private ?string $paymentMode = null;

/**
 * @ORM\Column(type="integer", nullable=true)
 */
private ?int $hiboutikSaleId = null;



    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInterventionNumber(): ?int
    {
        return $this->interventionNumber;
    }

    public function setInterventionNumber(int $interventionNumber): self
    {
        $this->interventionNumber = $interventionNumber;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $status = trim($status);
        $this->status = $status !== '' ? $status : 'Ouverte';
        return $this;
    }

    public function getCustomerLastName(): string
    {
        return $this->customerLastName;
    }

    public function setCustomerLastName(string $customerLastName): self
    {
        $this->customerLastName = trim($customerLastName);
        return $this;
    }

    public function getCustomerFirstName(): string
    {
        return $this->customerFirstName;
    }

    public function setCustomerFirstName(string $customerFirstName): self
    {
        $this->customerFirstName = trim($customerFirstName);
        return $this;
    }

    public function getCustomerPhone(): ?string
    {
        return $this->customerPhone;
    }

    public function setCustomerPhone(?string $customerPhone): self
    {
        $customerPhone = $customerPhone !== null ? trim($customerPhone) : null;
        $this->customerPhone = $customerPhone !== '' ? $customerPhone : null;
        return $this;
    }

    public function getDeviceLabel(): ?string
    {
        return $this->deviceLabel;
    }

    public function setDeviceLabel(?string $deviceLabel): self
    {
        $deviceLabel = $deviceLabel !== null ? trim($deviceLabel) : null;
        $this->deviceLabel = $deviceLabel !== '' ? $deviceLabel : null;
        return $this;
    }

    public function getWorkToDo(): ?string
    {
        return $this->workToDo;
    }

    public function setWorkToDo(?string $workToDo): self
    {
        $workToDo = $workToDo !== null ? trim($workToDo) : null;
        $this->workToDo = $workToDo !== '' ? $workToDo : null;
        return $this;
    }

    public function getHiboutikCustomerId(): ?int
    {
        return $this->hiboutikCustomerId;
    }

    public function setHiboutikCustomerId(?int $hiboutikCustomerId): self
    {
        $this->hiboutikCustomerId = $hiboutikCustomerId;
        return $this;
    }

    public function getUnlockPayloadEncrypted(): ?string
    {
        return $this->unlockPayloadEncrypted;
    }

    public function setUnlockPayloadEncrypted(?string $unlockPayloadEncrypted): self
    {
        $this->unlockPayloadEncrypted = $unlockPayloadEncrypted;
        return $this;
    }

    public function getUnlockSummary(): ?string
    {
        return $this->unlockSummary;
    }

    public function setUnlockSummary(?string $unlockSummary): self
    {
        $this->unlockSummary = $unlockSummary;
        return $this;
    }

    public function getTabletToken(): ?string
    {
        return $this->tabletToken;
    }

    public function setTabletToken(?string $tabletToken): self
    {
        $this->tabletToken = $tabletToken;
        return $this;
    }

    public function getTabletTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->tabletTokenExpiresAt;
    }

    public function setTabletTokenExpiresAt(?\DateTimeImmutable $tabletTokenExpiresAt): self
    {
        $this->tabletTokenExpiresAt = $tabletTokenExpiresAt;
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

public function getAmountTotal(): ?string
{
    return $this->amountTotal;
}

public function setAmountTotal(?string $amountTotal): self
{
    $this->amountTotal = $amountTotal;
    return $this;
}

public function getAmountPaid(): ?string
{
    return $this->amountPaid;
}

public function setAmountPaid(?string $amountPaid): self
{
    $this->amountPaid = $amountPaid;
    return $this;
}

public function getPaymentMode(): ?string
{
    return $this->paymentMode;
}

public function setPaymentMode(?string $paymentMode): self
{
    $this->paymentMode = $paymentMode;
    return $this;
}

public function getHiboutikSaleId(): ?int
{
    return $this->hiboutikSaleId;
}

public function setHiboutikSaleId(?int $hiboutikSaleId): self
{
    $this->hiboutikSaleId = $hiboutikSaleId;
    return $this;
}

public function getRemainingAmount(): float
{
    $total = (float) ($this->amountTotal ?? 0);
    $paid = (float) ($this->amountPaid ?? 0);

    return max(0, $total - $paid);
}

public function isFullyPaid(): bool
{
    $total = (float) ($this->amountTotal ?? 0);
    $paid = (float) ($this->amountPaid ?? 0);

    return $total > 0 && $paid >= $total;
}

}