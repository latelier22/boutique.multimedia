<?php

namespace App\Entity\Hiboutik;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(
 *     name="hib_invoice_pointing_row",
 *     indexes={
 *         @ORM\Index(name="idx_invoice_pointing_row_invoice_number", columns={"invoice_number"}),
 *         @ORM\Index(name="idx_invoice_pointing_row_invoice_date", columns={"invoice_date"}),
 *         @ORM\Index(name="idx_invoice_pointing_row_sku", columns={"sku"}),
 *         @ORM\Index(name="idx_invoice_pointing_row_ean", columns={"ean"}),
 *         @ORM\Index(name="idx_invoice_pointing_row_imei", columns={"imei"}),
 *         @ORM\Index(name="idx_invoice_pointing_row_matched_product_id", columns={"matched_product_id"}),
 *         @ORM\Index(name="idx_invoice_pointing_row_is_pointed", columns={"is_pointed"})
 *     }
 * )
 */
class InvoicePointingRow
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(name="id", type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Hiboutik\InvoicePointingSession", inversedBy="rows")
     * @ORM\JoinColumn(name="session_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?InvoicePointingSession $session = null;

    /**
     * @ORM\Column(name="line_number", type="integer")
     */
    private int $lineNumber = 0;

    /**
     * @ORM\Column(name="page_number", type="integer", nullable=true)
     */
    private ?int $pageNumber = null;

    /**
     * @ORM\Column(name="invoice_number", type="string", length=64, nullable=true)
     */
    private ?string $invoiceNumber = null;

    /**
     * @ORM\Column(name="invoice_date", type="date_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $invoiceDate = null;

    /**
     * @ORM\Column(name="sku", type="string", length=255, nullable=true)
     */
    private ?string $sku = null;

    /**
     * @ORM\Column(name="ean", type="string", length=255, nullable=true)
     */
    private ?string $ean = null;

    /**
     * IMEI principal ou chaîne IMEI brute
     *
     * @ORM\Column(name="imei", type="string", length=255, nullable=true)
     */
    private ?string $imei = null;

    /**
     * Liste d'IMEI si plusieurs unités
     *
     * @ORM\Column(name="imeis", type="json", nullable=true)
     */
    private ?array $imeis = null;

    /**
     * @ORM\Column(name="raw_label", type="text")
     */
    private string $rawLabel = '';

    /**
     * @ORM\Column(name="quantity", type="integer")
     */
    private int $quantity = 1;

    /**
     * @ORM\Column(name="buy_price", type="float")
     */
    private float $buyPrice = 0.0;

    /**
     * @ORM\Column(name="total_ht", type="float", nullable=true)
     */
    private ?float $totalHt = null;

    /**
     * @ORM\Column(name="vat", type="string", length=50, nullable=true)
     */
    private ?string $vat = null;

    /**
     * @ORM\Column(name="currency_code", type="string", length=8, nullable=true)
     */
    private ?string $currencyCode = null;

    /**
     * none | imei | ean | sku | manual | conflict
     *
     * @ORM\Column(name="match_type", type="string", length=32, nullable=true)
     */
    private ?string $matchType = null;

    /**
     * ID du produit Hiboutik pointé
     *
     * @ORM\Column(name="matched_product_id", type="integer", nullable=true)
     */
    private ?int $matchedProductId = null;

    /**
     * @ORM\Column(name="is_pointed", type="boolean")
     */
    private bool $isPointed = false;

    /**
     * @ORM\Column(name="pointed_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $pointedAt = null;

    /**
     * draft | pointed | error
     *
     * @ORM\Column(name="status", type="string", length=32)
     */
    private string $status = 'draft';

    /**
     * @ORM\Column(name="error_message", type="text", nullable=true)
     */
    private ?string $errorMessage = null;

    /**
     * @ORM\Column(name="raw_data", type="json")
     */
    private array $rawData = [];

    /**
     * @ORM\Column(name="created_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\Column(name="updated_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ?InvoicePointingSession
    {
        return $this->session;
    }

    public function setSession(?InvoicePointingSession $session): self
    {
        $this->session = $session;
        return $this;
    }

    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }

    public function setLineNumber(int $lineNumber): self
    {
        $this->lineNumber = $lineNumber;
        return $this;
    }

    public function getPageNumber(): ?int
    {
        return $this->pageNumber;
    }

    public function setPageNumber(?int $pageNumber): self
    {
        $this->pageNumber = $pageNumber;
        return $this;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function setInvoiceNumber(?string $invoiceNumber): self
    {
        $this->invoiceNumber = $invoiceNumber ? trim($invoiceNumber) : null;
        return $this;
    }

    public function getInvoiceDate(): ?\DateTimeImmutable
    {
        return $this->invoiceDate;
    }

    public function setInvoiceDate(?\DateTimeImmutable $invoiceDate): self
    {
        $this->invoiceDate = $invoiceDate;
        return $this;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(?string $sku): self
    {
        $this->sku = $sku ? trim($sku) : null;
        return $this;
    }

    public function getEan(): ?string
    {
        return $this->ean;
    }

    public function setEan(?string $ean): self
    {
        $this->ean = $ean ? trim($ean) : null;
        return $this;
    }

    public function getImei(): ?string
    {
        return $this->imei;
    }

 public function setImei(?string $imei): self
{
    if ($imei === null) {
        $this->imei = null;
        return $this;
    }

    $imei = trim($imei);
    $this->imei = $imei === '' ? null : substr($imei, 0, 255);

    return $this;
}

    public function getImeis(): ?array
    {
        return $this->imeis;
    }

   public function setImeis(?array $imeis): self
{
    if ($imeis === null) {
        $this->imeis = null;
        return $this;
    }

    $clean = array_values(array_filter(array_map(
        static function ($v): string {
            return preg_replace('/\D+/', '', trim((string) $v)) ?? '';
        },
        $imeis
    ), static fn ($v) => $v !== ''));

    $this->imeis = array_values(array_unique($clean));

    if (($this->imei === null || $this->imei === '') && !empty($this->imeis)) {
        $this->imei = $this->imeis[0];
    }

    return $this;
}

    public function getRawLabel(): string
    {
        return $this->rawLabel;
    }

    public function setRawLabel(string $rawLabel): self
    {
        $this->rawLabel = trim($rawLabel);
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = max(1, $quantity);
        return $this;
    }

    public function getBuyPrice(): float
    {
        return $this->buyPrice;
    }

    public function setBuyPrice(float $buyPrice): self
    {
        $this->buyPrice = $buyPrice;
        return $this;
    }

    public function getTotalHt(): ?float
    {
        return $this->totalHt;
    }

    public function setTotalHt(?float $totalHt): self
    {
        $this->totalHt = $totalHt;
        return $this;
    }

    public function getVat(): ?string
    {
        return $this->vat;
    }

    public function setVat(?string $vat): self
    {
        $this->vat = $vat ? trim($vat) : null;
        return $this;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(?string $currencyCode): self
    {
        $this->currencyCode = $currencyCode ? trim($currencyCode) : null;
        return $this;
    }

    public function getMatchType(): ?string
    {
        return $this->matchType;
    }

    public function setMatchType(?string $matchType): self
    {
        $this->matchType = $matchType ? trim($matchType) : null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getMatchedProductId(): ?int
    {
        return $this->matchedProductId;
    }

    public function setMatchedProductId(?int $matchedProductId): self
    {
        $this->matchedProductId = $matchedProductId;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isPointed(): bool
    {
        return $this->isPointed;
    }

    public function setIsPointed(bool $isPointed): self
    {
        $this->isPointed = $isPointed;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getPointedAt(): ?\DateTimeImmutable
    {
        return $this->pointedAt;
    }

    public function setPointedAt(?\DateTimeImmutable $pointedAt): self
    {
        $this->pointedAt = $pointedAt;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = trim($status) !== '' ? trim($status) : 'draft';
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage ? trim($errorMessage) : null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }

    public function setRawData(array $rawData): self
    {
        $this->rawData = $rawData;
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function hasImei(): bool
{
    if ($this->imei !== null && trim($this->imei) !== '') {
        return true;
    }

    return is_array($this->imeis) && count($this->imeis) > 0;
}

public function getImeisText(): string
{
    if (is_array($this->imeis) && count($this->imeis) > 0) {
        return implode(', ', $this->imeis);
    }

    return $this->imei ?? '';
}
}