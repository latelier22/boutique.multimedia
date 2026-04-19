<?php

namespace App\Entity\Hiboutik;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="hib_mobile_import_row")
 */
class MobileImportRow
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Hiboutik\MobileImportSession", inversedBy="rows")
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private ?MobileImportSession $session = null;

    /**
     * @ORM\Column(type="integer")
     */
    private int $lineNumber = 0;

    /**
     * Si quantité > 1 sur commande, on éclate en unités
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $unitIndex = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $sku = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $ean = null;

    /**
    * @ORM\Column(type="text")
    */
    private string $rawLabel = '';

    /**
     * Toujours destiné à devenir le code-barres produit
     *
     * @ORM\Column(type="string", length=32, nullable=true)
     */
    private ?string $imei = null;

    /**
     * @ORM\Column(type="integer")
     */
    private int $quantity = 1;

    /**
     * @ORM\Column(type="float")
     */
    private float $buyPrice = 0.0;

    /**
     * @ORM\Column(type="string", length=8, nullable=true)
     */
    private ?string $currencyCode = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $parsedBrand = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $parsedModel = null;

    /**
     * @ORM\Column(type="string", length=64, nullable=true)
     */
    private ?string $parsedStorage = null;

    /**
     * @ORM\Column(type="string", length=64, nullable=true)
     */
    private ?string $parsedColor = null;

    /**
     * @ORM\Column(type="string", length=16, nullable=true)
     */
    private ?string $parsedGrade = null;

    /**
     * true si on est dans le cas bon de commande sans IMEI encore saisi
     *
     * @ORM\Column(type="boolean")
     */
    private bool $requiresImei = false;

    /**
     * Produit Hiboutik existant utilisé comme base
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $baseProductId = null;

    /**
     * Produit Hiboutik créé à la fin
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $createdProductId = null;

    /**
    * @ORM\Column(type="text", nullable=true)
    */
    private ?string $resolvedName = null;

    /**
     * Code-barres final retenu
     * IMEI si présent, sinon EAN, sinon valeur manuelle
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $resolvedBarcode = null;

    /**
     * Référence externe finale (souvent le SKU fournisseur)
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $resolvedProductsRefExt = null;

    /**
     * TVA finale retenue
     * ex: 20 / 0 / margin / etc selon ton usage Hiboutik
     *
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private ?string $resolvedVat = null;

    /**
     * Compte comptable final
     *
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private ?string $resolvedAccountingAccount = null;

    /**
     * Prix d'achat final
     *
     * @ORM\Column(type="float", nullable=true)
     */
    private ?float $resolvedBuyPrice = null;

    /**
     * Prix de vente final
     *
     * @ORM\Column(type="float", nullable=true)
     */
    private ?float $resolvedSellPrice = null;

    /**
     * Catégorie Hiboutik finale
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $resolvedCategoryId = null;

    /**
     * Libellé catégorie pour affichage dans la grille
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $resolvedCategoryLabel = null;

    /**
     * Fournisseur final retenu pour la ligne
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $resolvedSupplierId = null;

    /**
     * Type de matching trouvé
     * barcode / sku / barcode+sku / none / conflict
     *
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private ?string $matchType = null;

    /**
     * Produit Hiboutik déjà trouvé
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $matchedProductId = null;

    /**
     * Ligne ignorée volontairement
     *
     * @ORM\Column(type="boolean")
     */
    private bool $isIgnored = false;

    /**
     * Ligne bloquée (IMEI déjà existant, conflit barcode/SKU, etc.)
     *
     * @ORM\Column(type="boolean")
     */
    private bool $isBlocked = false;

    /**
     * Raison du blocage
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $blockReason = null;

    /**
     * Ligne prête à être créée / importée
     *
     * @ORM\Column(type="boolean")
     */
    private bool $isReady = false;


    /**
     * draft | ready | imported | error
     *
     * @ORM\Column(type="string", length=32)
     */
    private string $status = 'draft';

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $errorMessage = null;

    /**
     * @ORM\Column(type="json")
     */
    private array $rawData = [];

        /**
     * Marque finale
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $resolvedBrandId = null;

    /**
     * Libellé marque finale
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $resolvedBrandLabel = null;

        public function getResolvedBrandId(): ?int
    {
        return $this->resolvedBrandId;
    }

    public function setResolvedBrandId(?int $resolvedBrandId): self
    {
        $this->resolvedBrandId = $resolvedBrandId;
        return $this;
    }

    public function getResolvedBrandLabel(): ?string
    {
        return $this->resolvedBrandLabel;
    }

    public function setResolvedBrandLabel(?string $resolvedBrandLabel): self
    {
        $this->resolvedBrandLabel = $resolvedBrandLabel ? trim($resolvedBrandLabel) : null;
        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ?MobileImportSession
    {
        return $this->session;
    }

    public function setSession(?MobileImportSession $session): self
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

    public function getUnitIndex(): ?int
    {
        return $this->unitIndex;
    }

    public function setUnitIndex(?int $unitIndex): self
    {
        $this->unitIndex = $unitIndex;

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

    public function getRawLabel(): string
    {
        return $this->rawLabel;
    }

    public function setRawLabel(string $rawLabel): self
    {
        $this->rawLabel = trim($rawLabel);

        return $this;
    }

    public function getImei(): ?string
    {
        return $this->imei;
    }

    public function setImei(?string $imei): self
    {
        $this->imei = $imei;

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

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(?string $currencyCode): self
    {
        $this->currencyCode = $currencyCode ? trim($currencyCode) : null;

        return $this;
    }

    public function getParsedBrand(): ?string
    {
        return $this->parsedBrand;
    }

    public function setParsedBrand(?string $parsedBrand): self
    {
        $this->parsedBrand = $parsedBrand ? trim($parsedBrand) : null;

        return $this;
    }

    public function getParsedModel(): ?string
    {
        return $this->parsedModel;
    }

    public function setParsedModel(?string $parsedModel): self
    {
        $this->parsedModel = $parsedModel ? trim($parsedModel) : null;

        return $this;
    }

    public function getParsedStorage(): ?string
    {
        return $this->parsedStorage;
    }

    public function setParsedStorage(?string $parsedStorage): self
    {
        $this->parsedStorage = $parsedStorage ? trim($parsedStorage) : null;

        return $this;
    }

    public function getParsedColor(): ?string
    {
        return $this->parsedColor;
    }

    public function setParsedColor(?string $parsedColor): self
    {
        $this->parsedColor = $parsedColor ? trim($parsedColor) : null;

        return $this;
    }

    public function getParsedGrade(): ?string
    {
        return $this->parsedGrade;
    }

    public function setParsedGrade(?string $parsedGrade): self
    {
        $this->parsedGrade = $parsedGrade ? trim($parsedGrade) : null;

        return $this;
    }

    public function isRequiresImei(): bool
    {
        return $this->requiresImei;
    }

    public function setRequiresImei(bool $requiresImei): self
    {
        $this->requiresImei = $requiresImei;

        return $this;
    }

    public function getBaseProductId(): ?int
    {
        return $this->baseProductId;
    }

    public function setBaseProductId(?int $baseProductId): self
    {
        $this->baseProductId = $baseProductId;

        return $this;
    }

    public function getCreatedProductId(): ?int
    {
        return $this->createdProductId;
    }

    public function setCreatedProductId(?int $createdProductId): self
    {
        $this->createdProductId = $createdProductId;

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

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

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

    public function getResolvedName(): ?string
    {
        return $this->resolvedName;
    }

    public function setResolvedName(?string $resolvedName): self
    {
        $this->resolvedName = $resolvedName ? trim($resolvedName) : null;
        return $this;
    }

    public function getResolvedBarcode(): ?string
    {
        return $this->resolvedBarcode;
    }

    public function setResolvedBarcode(?string $resolvedBarcode): self
    {
        $this->resolvedBarcode = $resolvedBarcode ? trim($resolvedBarcode) : null;
        return $this;
    }

    public function getResolvedProductsRefExt(): ?string
    {
        return $this->resolvedProductsRefExt;
    }

    public function setResolvedProductsRefExt(?string $resolvedProductsRefExt): self
    {
        $this->resolvedProductsRefExt = $resolvedProductsRefExt ? trim($resolvedProductsRefExt) : null;
        return $this;
    }

    public function getResolvedVat(): ?string
    {
        return $this->resolvedVat;
    }

    public function setResolvedVat(?string $resolvedVat): self
{
    if ($resolvedVat === null) {
        $this->resolvedVat = null;
        return $this;
    }

    $resolvedVat = trim($resolvedVat);
    $this->resolvedVat = $resolvedVat === '' ? null : $resolvedVat;

    return $this;
}

    public function getResolvedAccountingAccount(): ?string
    {
        return $this->resolvedAccountingAccount;
    }

    public function setResolvedAccountingAccount(?string $resolvedAccountingAccount): self
    {
        $this->resolvedAccountingAccount = $resolvedAccountingAccount ? trim($resolvedAccountingAccount) : null;
        return $this;
    }

    public function getResolvedBuyPrice(): ?float
    {
        return $this->resolvedBuyPrice;
    }

    public function setResolvedBuyPrice(?float $resolvedBuyPrice): self
    {
        $this->resolvedBuyPrice = $resolvedBuyPrice;
        return $this;
    }

    public function getResolvedSellPrice(): ?float
    {
        return $this->resolvedSellPrice;
    }

    public function setResolvedSellPrice(?float $resolvedSellPrice): self
    {
        $this->resolvedSellPrice = $resolvedSellPrice;
        return $this;
    }

    public function getResolvedCategoryId(): ?int
    {
        return $this->resolvedCategoryId;
    }

    public function setResolvedCategoryId(?int $resolvedCategoryId): self
    {
        $this->resolvedCategoryId = $resolvedCategoryId;
        return $this;
    }

    public function getResolvedCategoryLabel(): ?string
    {
        return $this->resolvedCategoryLabel;
    }

    public function setResolvedCategoryLabel(?string $resolvedCategoryLabel): self
    {
        $this->resolvedCategoryLabel = $resolvedCategoryLabel ? trim($resolvedCategoryLabel) : null;
        return $this;
    }

    public function getResolvedSupplierId(): ?int
    {
        return $this->resolvedSupplierId;
    }

    public function setResolvedSupplierId(?int $resolvedSupplierId): self
    {
        $this->resolvedSupplierId = $resolvedSupplierId;
        return $this;
    }

    public function getMatchType(): ?string
    {
        return $this->matchType;
    }

    public function setMatchType(?string $matchType): self
    {
        $this->matchType = $matchType ? trim($matchType) : null;
        return $this;
    }

    public function getMatchedProductId(): ?int
    {
        return $this->matchedProductId;
    }

    public function setMatchedProductId(?int $matchedProductId): self
    {
        $this->matchedProductId = $matchedProductId;
        return $this;
    }

    public function isIgnored(): bool
    {
        return $this->isIgnored;
    }

    public function setIsIgnored(bool $isIgnored): self
    {
        $this->isIgnored = $isIgnored;
        return $this;
    }

    public function isBlocked(): bool
    {
        return $this->isBlocked;
    }

    public function setIsBlocked(bool $isBlocked): self
    {
        $this->isBlocked = $isBlocked;
        return $this;
    }

    public function getBlockReason(): ?string
    {
        return $this->blockReason;
    }

    public function setBlockReason(?string $blockReason): self
    {
        $this->blockReason = $blockReason ? trim($blockReason) : null;
        return $this;
    }

    public function isReady(): bool
    {
        return $this->isReady;
    }

    public function setIsReady(bool $isReady): self
    {
        $this->isReady = $isReady;
        return $this;
    }


        public function isReadyToCreate(): bool
    {
        if ($this->isIgnored) {
            return false;
        }

        if ($this->isBlocked) {
            return false;
        }

        if (!$this->resolvedName || trim($this->resolvedName) === '') {
            return false;
        }

        if ($this->resolvedBuyPrice === null || $this->resolvedBuyPrice <= 0) {
            return false;
        }

        if ($this->resolvedVat === null || trim($this->resolvedVat) === '') {
            return false;
        }

        if (!$this->resolvedAccountingAccount || trim($this->resolvedAccountingAccount) === '') {
            return false;
        }

        if (!$this->resolvedCategoryId) {
            return false;
        }

        if ($this->requiresImei && (!$this->resolvedBarcode || trim($this->resolvedBarcode) === '')) {
            return false;
        }

        return true;
    }
}