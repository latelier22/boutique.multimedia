<?php

namespace App\Service\Hiboutik;

use App\Entity\Hiboutik\MobileImportRow;
use App\Service\HiboutikClient;
use App\Service\CacheApiClient;

class MobileImportProductCreator
{
    /**
     * Cache local des produits Hiboutik pendant UNE requête.
     */
    private ?array $productsCache = null;

    public function __construct(
        private HiboutikClient $hib,
        private CacheApiClient $cacheApi,
    ) {
    }

    /**
     * Flux complet :
     * 1) recherche produit existant par barcode et/ou ref ext
     * 2) si trouvé :
     *    - si IMEI déjà existant => blocage
     *    - sinon réutilisation
     * 3) sinon création minimale
     * 4) puis update complémentaire
     * 5) puis ajout dans l'arrivage
     */
    public function findOrCreateFromRow(MobileImportRow $row, ?int $inventoryInputId = null): array
    {
        $existing = $this->findExistingProductByBarcodeOrSku($row);

        if (!($existing['ok'] ?? false)) {
            return $existing;
        }

        if (!empty($existing['product'])) {
            $product = $existing['product'];
            $productId = (int) ($product['product_id'] ?? 0);

            if ($productId <= 0) {
                return ['ok' => false, 'error' => 'existing_product_invalid'];
            }

            $imei = $this->normalizeLoose($row->getImei());
            $matchedOn = (array) ($existing['matched_on'] ?? []);

            // Si IMEI déjà trouvé par barcode => interdit
            if ($imei !== null && in_array('barcode', $matchedOn, true)) {
                return [
                    'ok' => false,
                    'error' => 'imei_already_exists',
                    'existing_product_id' => $productId,
                    'imei' => $imei,
                ];
            }

            if ($inventoryInputId && $inventoryInputId > 0) {
                $addRes = $this->hib->addProductToInventoryInput(
                    $inventoryInputId,
                    $productId,
                    max(1, $row->getQuantity()),
                    (float) ($row->getResolvedBuyPrice() ?? $row->getBuyPrice())
                );

                if (!($addRes['ok'] ?? false)) {
                    return [
                        'ok' => false,
                        'error' => 'inventory_add_failed_existing_product',
                        'product_id' => $productId,
                        'raw' => $addRes,
                    ];
                }
            }

            return [
                'ok' => true,
                'mode' => 'existing',
                'product_id' => $productId,
                'matched_on' => $matchedOn,
            ];
        }

        // ------------------------------------------------------------
        // Aucun produit trouvé => création minimale
        // ------------------------------------------------------------
        $createPayload = $this->buildCreatePayloadFromRow($row);
        $created = $this->hib->createProduct($createPayload);

        $productId = 0;
        if (is_array($created)) {
            if (isset($created['product_id'])) {
                $productId = (int) $created['product_id'];
            } elseif (isset($created[0]) && is_array($created[0]) && isset($created[0]['product_id'])) {
                $productId = (int) $created[0]['product_id'];
            }
        }

        if ($productId <= 0) {
            return [
                'ok' => false,
                'error' => 'product_create_failed',
                'payload' => $createPayload,
                'raw' => $created,
            ];
        }

        $this->cacheApi->refreshProduct($productId);

        // ------------------------------------------------------------
        // Update complémentaire après création
        // ------------------------------------------------------------
        $postCreateFields = $this->buildPostCreateFields($row);

        if ($postCreateFields !== []) {
            $updateRes = $this->hib->updateProductAttributes($productId, $postCreateFields);

            if (!($updateRes['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'error' => 'product_post_update_failed',
                    'product_id' => $productId,
                    'payload' => $postCreateFields,
                    'raw' => $updateRes,
                ];
            }
        }

        // ------------------------------------------------------------
        // Ajout dans l'arrivage
        // ------------------------------------------------------------
        if ($inventoryInputId && $inventoryInputId > 0) {
            $addRes = $this->hib->addProductToInventoryInput(
                $inventoryInputId,
                $productId,
                max(1, $row->getQuantity()),
                (float) ($row->getResolvedBuyPrice() ?? $row->getBuyPrice())
            );

            if (!($addRes['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'error' => 'inventory_add_failed_created_product',
                    'product_id' => $productId,
                    'raw' => $addRes,
                ];
            }
        }

        $this->cacheApi->refreshProduct($productId);

        return [
            'ok' => true,
            'mode' => 'created',
            'product_id' => $productId,
        ];
    }

    /**
     * Recherche produit existant par barcode et/ou ref ext.
     */
    private function findExistingProductByBarcodeOrSku(MobileImportRow $row): array
    {
        $barcode = $this->extractWantedBarcode($row);
        $sku = $this->normalizeLoose($row->getResolvedProductsRefExt() ?: $row->getSku());

        $byBarcode = null;
        $bySku = null;

        foreach ($this->getAllProductsCached() as $p) {
            if (!is_array($p)) {
                continue;
            }

            if ($byBarcode === null && $barcode !== null) {
                $productBarcode = $this->normalizeLoose((string) ($p['product_barcode'] ?? ''));
                if ($productBarcode !== null && $productBarcode === $barcode) {
                    $byBarcode = $p;
                }
            }

            if ($bySku === null && $sku !== null) {
                $productSku = $this->normalizeLoose((string) ($p['products_ref_ext'] ?? ''));
                if ($productSku !== null && $productSku === $sku) {
                    $bySku = $p;
                }
            }

            if ($byBarcode !== null && $bySku !== null) {
                break;
            }
        }

        if ($byBarcode && $bySku) {
            $barcodeId = (int) ($byBarcode['product_id'] ?? 0);
            $skuId = (int) ($bySku['product_id'] ?? 0);

            if ($barcodeId === $skuId) {
                return [
                    'ok' => true,
                    'product' => $byBarcode,
                    'matched_on' => ['barcode', 'sku'],
                ];
            }

            return [
                'ok' => false,
                'error' => 'barcode_sku_conflict',
                'barcode_product_id' => $barcodeId,
                'sku_product_id' => $skuId,
            ];
        }

        if ($byBarcode) {
            return [
                'ok' => true,
                'product' => $byBarcode,
                'matched_on' => ['barcode'],
            ];
        }

        if ($bySku) {
            return [
                'ok' => true,
                'product' => $bySku,
                'matched_on' => ['sku'],
            ];
        }

        return [
            'ok' => true,
            'product' => null,
            'matched_on' => [],
        ];
    }

    /**
     * Création MINIMALE.
     * On évite d'envoyer trop de champs au create Hiboutik.
     */
    private function buildCreatePayloadFromRow(MobileImportRow $row): array
    {
        $name = trim((string) ($row->getResolvedName() ?: $row->getRawLabel()));
        if ($name === '') {
            $name = 'Produit import ligne ' . $row->getLineNumber();
        }

        $buy = (float) ($row->getResolvedBuyPrice() ?? $row->getBuyPrice());
        $sell = (float) ($row->getResolvedSellPrice() ?? $row->getBuyPrice());

        return [
            'product_model'            => $name,
            'product_price'            => number_format($sell, 2, '.', ''),
            'product_supply_price'     => number_format($buy, 2, '.', ''),
            'product_stock_management' => 1,
            'product_display_www'      => 0,
            'product_arch'             => 0,
        ];
    }

    /**
     * Champs complémentaires à pousser APRÈS création.
     */
    private function buildPostCreateFields(MobileImportRow $row): array
    {
        $fields = [];

        $barcode = $this->extractWantedBarcode($row);
        if ($barcode !== null) {
            $fields['product_barcode'] = $barcode;
        }

        $refExt = $this->normalizeLoose($row->getResolvedProductsRefExt() ?: $row->getSku());
        if ($refExt !== null) {
            $fields['products_ref_ext'] = $refExt;
        }

        $supplierId = (int) ($row->getSession()?->getSupplierId() ?: 0);
        if ($supplierId > 0) {
            $fields['product_supplier'] = (string) $supplierId;
        }

        $categoryId = (int) ($row->getResolvedCategoryId() ?: 0);
        if ($categoryId > 0) {
            $fields['product_category'] = (string) $categoryId;
        }

        if (method_exists($row, 'getResolvedBrandId')) {
            $brandId = (int) ($row->getResolvedBrandId() ?: 0);
            if ($brandId > 0) {
                $fields['product_brand'] = (string) $brandId;
            }
        }

        $vatCode = $this->resolveHiboutikVatCode($row->getResolvedVat());
        if ($vatCode !== null) {
            $fields['product_vat'] = $vatCode;
        }

        $account = $this->normalizeAccountingAccount($row->getResolvedAccountingAccount());
        if ($account !== null) {
            $fields['accounting_account'] = $account;
        }

        $buy = (float) ($row->getResolvedBuyPrice() ?? $row->getBuyPrice());
        $sell = (float) ($row->getResolvedSellPrice() ?? $row->getBuyPrice());

        $fields['product_supply_price'] = number_format($buy, 2, '.', '');
        $fields['product_price'] = number_format($sell, 2, '.', '');

        return $fields;
    }

    /**
     * Conversion TVA métier -> code Hiboutik.
     * Chez toi :
     * 20% => 1
     * 0%  => 5
     */
    private function resolveHiboutikVatCode(?string $vat): ?string
    {
        $vat = trim((string) $vat);

        return match ($vat) {
            '20' => '1',
            '0'  => '5',
            '1', '5' => $vat,
            default => null,
        };
    }

    /**
     * Compte compta compatible Hiboutik.
     */
    private function normalizeAccountingAccount(?string $account): ?string
    {
        $account = trim((string) $account);

        if ($account === '') {
            return '707002';
        }

        if (in_array($account, ['707001', '707002'], true)) {
            return $account;
        }

        return '707002';
    }

    /**
     * Barcode final :
     * - resolvedBarcode
     * - sinon IMEI
     * - sinon EAN
     * - sinon SKU
     * - sinon null
     */
    private function extractWantedBarcode(MobileImportRow $row): ?string
    {
        $barcode = $this->normalizeLoose($row->getResolvedBarcode());
        if ($barcode !== null) {
            return $barcode;
        }

        $imei = $this->normalizeLoose($row->getImei());
        if ($imei !== null) {
            return $imei;
        }

        $ean = $this->normalizeLoose($row->getEan());
        if ($ean !== null) {
            return $ean;
        }

        $sku = $this->normalizeLoose($row->getSku());
        if ($sku !== null) {
            return $sku;
        }

        return null;
    }

    /**
     * Normalisation simple.
     */
    private function normalizeLoose(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * Charge tout le catalogue une seule fois.
     */
    private function getAllProductsCached(): array
    {
        if ($this->productsCache !== null) {
            return $this->productsCache;
        }

        $products = $this->hib->getProductsAll(50);
        $this->productsCache = is_array($products) ? $products : [];

        return $this->productsCache;
    }

public function reapplyResolvedFieldsToExistingProduct(MobileImportRow $row): array
{
    $productId = (int) ($row->getCreatedProductId() ?? 0);

    if ($productId <= 0) {
        return [
            'ok' => false,
            'error' => 'no_created_product_id',
        ];
    }

    $fields = $this->buildPostCreateFields($row);

    if ($fields === []) {
        return [
            'ok' => true,
            'product_id' => $productId,
            'updated_fields' => [],
        ];
    }

    $updateRes = $this->hib->updateProductAttributes($productId, $fields);

    if (!($updateRes['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'product_reapply_failed',
            'product_id' => $productId,
            'payload' => $fields,
            'raw' => $updateRes,
        ];
    }
    $this->cacheApi->refreshProduct($productId);
    return [
        'ok' => true,
        'product_id' => $productId,
        'updated_fields' => array_keys($fields),
    ];
}

}