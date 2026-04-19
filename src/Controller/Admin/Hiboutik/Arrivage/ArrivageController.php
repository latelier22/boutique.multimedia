<?php

namespace App\Controller\Admin\Hiboutik\Arrivage;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Rachat\Rachat;
use App\Entity\Hiboutik\MobileImportRow;
use App\Entity\Hiboutik\MobileImportSession;
use App\Service\HiboutikClient;

#[Route('/admin/arrivages', name: 'admin_arrivages_')]
final class ArrivageController extends AbstractController
{
    public function __construct(
        private HiboutikClient $hib,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $arrivages = $this->hib->listAllInventoryInputs();

        return $this->render('@SyliusAdmin/Arrivages/index.html.twig', [
            'arrivages' => $arrivages,
        ]);
    }

    #[Route('/rachats', name: 'rachats', methods: ['GET'])]
    public function rachats(): Response
    {
        $arrivages = $this->hib->listRachatInputs();

        return $this->render('@SyliusAdmin/Arrivages/index.html.twig', [
            'arrivages' => $arrivages,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(): Response
    {
        $label = 'RACHAT MENSUEL-' . (new \DateTime())->format('m-Y');

        $existants = $this->hib->listMonthlyRachatInputs();
        foreach ($existants as $a) {
            if (($a['inventory_input_label'] ?? '') === $label) {
                $this->addFlash('info', "L’arrivage $label existe déjà.");
                return $this->redirectToRoute('admin_arrivages_index');
            }
        }

        $res = $this->hib->createInventoryInput(1, 3, $label);

        if (!($res['ok'] ?? false)) {
            $this->addFlash('error', 'Erreur Hiboutik (' . ($res['status'] ?? '??') . ')');
        } else {
            $this->addFlash('success', "Nouvel arrivage créé : $label");
        }

        return $this->redirectToRoute('admin_arrivages_index');
    }

    #[Route('/validate/{id}', name: 'validate', methods: ['POST'])]
    public function validate(int $id): Response
    {
        $res = $this->hib->validateInventoryInput($id);

        if (!($res['ok'] ?? false)) {
            $dbg = $this->hib->getLastDebug();
            $this->addFlash(
                'error',
                'Erreur Hiboutik (' . ($res['status'] ?? '??') . ') : ' . substr((string)($dbg['raw'] ?? ''), 0, 200)
            );

            return $this->redirectToRoute('admin_arrivages_index');
        }

        $this->addFlash('success', "Arrivage #$id validé !");

        return $this->redirectToRoute('admin_arrivages_index');
    }

    #[Route('/details/{id}', name: 'details', methods: ['GET'])]
    public function details(int $id): Response
    {
        $res = $this->hib->listInventoryInputDetails($id);
        $data = $res['data'] ?? [];

        return $this->render('@SyliusAdmin/Arrivages/details.html.twig', [
            'id' => $id,
            'details' => $data,
        ]);
    }

    #[Route('/{id}/session', name: 'session', methods: ['POST'])]
    public function openOrCreateSession(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('arrivage_session_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRF invalid');
        }

        /** @var MobileImportSession|null $existing */
        $existing = $this->em->getRepository(MobileImportSession::class)->findOneBy([
            'hibInventoryInputId' => $id,
        ], [
            'id' => 'DESC',
        ]);

        if ($existing) {
            $this->addFlash('info', 'Session existante réouverte (#' . $existing->getId() . ').');

            return $this->redirectToRoute('admin_hib_mobile_import_show', [
                'id' => $existing->getId(),
            ]);
        }

        return $this->buildSessionFromInventoryInput($id);
    }

    #[Route('/{id}/session/rebuild', name: 'session_rebuild', methods: ['POST'])]
    public function rebuildSessionFromArrivage(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('arrivage_session_rebuild_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRF invalid');
        }

        /** @var MobileImportSession|null $existing */
        $existing = $this->em->getRepository(MobileImportSession::class)->findOneBy([
            'hibInventoryInputId' => $id,
        ], [
            'id' => 'DESC',
        ]);

        if ($existing) {
            foreach ($existing->getRows() as $row) {
                $this->em->remove($row);
            }

            $this->em->remove($existing);
            $this->em->flush();
        }

        return $this->buildSessionFromInventoryInput($id);
    }

    private function buildSessionFromInventoryInput(int $id): Response
    {
        $arrivage = $this->findInventoryInputRowById($id);
        if (!$arrivage) {
            $this->addFlash('error', 'Arrivage introuvable.');
            return $this->redirectToRoute('admin_arrivages_index');
        }

        $detailsRes = $this->hib->listInventoryInputDetails($id);
        $details = is_array($detailsRes['data'] ?? null) ? $detailsRes['data'] : [];

        if (!($detailsRes['ok'] ?? false) && $details === []) {
            $this->addFlash('error', 'Impossible de lire les lignes de l’arrivage.');
            return $this->redirectToRoute('admin_arrivages_index');
        }

        $categoryMap = $this->buildCategoryMap();
        $brandMap = $this->buildBrandMap();
        $supplierMap = $this->buildSupplierMap();

        $label = trim((string) ($arrivage['inventory_input_label'] ?? 'Arrivage #' . $id));
        $supplierId = $this->extractSupplierIdFromInventoryInput($arrivage);
        $documentCode = $this->extractDocumentCodeFromInventoryLabel($label);
        $sourceType = $this->detectSourceTypeFromInventoryLabel($label);
        $documentDate = $this->extractInventoryInputDate($arrivage);

        $session = new MobileImportSession();
        $session->setSourceType($sourceType);
        $session->setSourceFilename($label !== '' ? $label : ('Arrivage Hiboutik #' . $id));
        $session->setStoredFilename(null);
        $session->setDocumentCode($documentCode);
        $session->setSupplierId($supplierId);
        $session->setHibInventoryInputId($id);
        $session->setStatus('prepared');

        if ($documentDate) {
            $session->setDocumentDate($documentDate);
        }

        $session->setMeta([
            'source' => 'hib_inventory_input',
            'inventory_input_id' => $id,
            'inventory_input_label' => $label,
            'inventory_input_row' => $arrivage,
        ]);

        $productCache = [];
        $lineNumber = 1;

        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $productId = $this->extractProductIdFromInventoryDetail($detail);
            $detailLabel = $this->extractLabelFromInventoryDetail($detail);
            $quantity = $this->extractQuantityFromInventoryDetail($detail);
            $detailBuyPrice = $this->extractBuyPriceFromInventoryDetail($detail);
            $detailBarcode = $this->extractBarcodeFromInventoryDetail($detail);
            $detailSku = $this->extractSkuFromInventoryDetail($detail);

            $product = [];
            if ($productId > 0) {
                if (!array_key_exists($productId, $productCache)) {
                    try {
                        $productCache[$productId] = $this->hib->getProduct($productId);
                    } catch (\Throwable $e) {
                        $productCache[$productId] = [];
                    }
                }
                $product = is_array($productCache[$productId]) ? $productCache[$productId] : [];
            }

            $resolvedName = $this->extractProductNameFromProduct($product)
                ?: ($detailLabel !== '' ? $detailLabel : ('Produit arrivage #' . $id));

            $resolvedBarcode = $this->extractBarcodeFromProduct($product) ?: $detailBarcode;
            $resolvedRefExt = $this->extractSkuFromProduct($product) ?: $detailSku;
            $resolvedBuyPrice = $detailBuyPrice > 0 ? $detailBuyPrice : $this->extractBuyPriceFromProduct($product);
            $resolvedSellPrice = $this->extractSellPriceFromProduct($product);

            $resolvedSupplierId = $this->extractSupplierIdFromProduct($product) ?: $supplierId;
            $resolvedCategoryId = $this->extractCategoryIdFromProduct($product);
            $resolvedBrandId = $this->extractBrandIdFromProduct($product);

            $resolvedCategoryLabel = $resolvedCategoryId ? ($categoryMap[$resolvedCategoryId] ?? null) : null;
            $resolvedBrandLabel = $resolvedBrandId ? ($brandMap[$resolvedBrandId] ?? null) : null;
            $resolvedSupplierLabel = $resolvedSupplierId ? ($supplierMap[$resolvedSupplierId] ?? null) : null;

            $resolvedVat = $this->readVatChoiceFromProduct($product);
            $resolvedAccountingAccount = $this->readAccountingAccountFromProduct($product);

            $row = new MobileImportRow();
            $row->setLineNumber($lineNumber++);
            $row->setUnitIndex(null);
            $row->setSku($resolvedRefExt ?: $detailSku);
            $row->setRawLabel($detailLabel !== '' ? $detailLabel : $resolvedName);
            $row->setQuantity($quantity);
            $row->setBuyPrice($resolvedBuyPrice > 0 ? $resolvedBuyPrice : 0.0);
            $row->setCurrencyCode('EUR');
            $row->setParsedBrand($resolvedBrandLabel);
            $row->setParsedModel($resolvedName);
            $row->setParsedStorage(null);
            $row->setParsedColor(null);
            $row->setParsedGrade(null);
            $row->setRequiresImei(false);

            $barcodeDigits = preg_replace('/\D+/', '', (string) ($resolvedBarcode ?? ''));
            if ($barcodeDigits !== '' && strlen($barcodeDigits) === 15) {
                $row->setImei($resolvedBarcode);
                $row->setEan(null);
            } else {
                $row->setImei(null);
                $row->setEan($resolvedBarcode);
            }

            if ($productId > 0) {
                $row->setBaseProductId($productId);
                $row->setMatchedProductId($productId);
                $row->setCreatedProductId($productId);
                $row->setMatchType('inventory_input');
                $row->setStatus('imported');
                $row->setErrorMessage('Déjà traité');
            } else {
                $row->setMatchType('none');
                $row->setStatus('draft');
                $row->setErrorMessage(null);
            }

            $row->setResolvedName($resolvedName);
            $row->setResolvedBarcode($resolvedBarcode ?: null);
            $row->setResolvedProductsRefExt($resolvedRefExt ?: null);
            $row->setResolvedBuyPrice($resolvedBuyPrice > 0 ? $resolvedBuyPrice : null);
            $row->setResolvedSellPrice($resolvedSellPrice > 0 ? $resolvedSellPrice : null);
$row->setResolvedVat($resolvedVat !== null && $resolvedVat !== '' ? $resolvedVat : null);
$row->setResolvedAccountingAccount($resolvedAccountingAccount !== null && $resolvedAccountingAccount !== '' ? $resolvedAccountingAccount : null);
            $row->setResolvedSupplierId($resolvedSupplierId ?: $supplierId);
            $row->setResolvedCategoryId($resolvedCategoryId);
            $row->setResolvedCategoryLabel($resolvedCategoryLabel);
            $row->setResolvedBrandId($resolvedBrandId);
            $row->setResolvedBrandLabel($resolvedBrandLabel);
            $row->setIsIgnored(false);
            $row->setIsBlocked(false);
            $row->setBlockReason(null);
            $row->setIsReady($productId > 0 ? false : $row->isReadyToCreate());

            $row->setRawData([
                'source' => 'hib_inventory_input',
                'inventory_input_id' => $id,
                'inventory_detail' => $detail,
                'hib_product' => $product,
                'resolved_supplier_name' => $resolvedSupplierLabel,
            ]);

            $session->addRow($row);
        }

        $this->em->persist($session);
        $this->em->flush();

        $this->addFlash('success', 'Session créée depuis l’arrivage (#' . $session->getId() . ').');

        return $this->redirectToRoute('admin_hib_mobile_import_show', [
            'id' => $session->getId(),
        ]);
    }

    private function findInventoryInputRowById(int $id): ?array
    {
        foreach ($this->hib->listAllInventoryInputs() as $row) {
            if ((int) ($row['inventory_input_id'] ?? 0) === $id) {
                return is_array($row) ? $row : null;
            }
        }

        return null;
    }

    private function extractSupplierIdFromInventoryInput(array $row): ?int
    {
        foreach (['supplier_id', 'inventory_input_supplier_id', 'product_supplier', 'supplier'] as $key) {
            $value = isset($row[$key]) ? (int) $row[$key] : 0;
            if ($value > 0) {
                return $value;
            }
        }

        return null;
    }

    private function extractInventoryInputDate(array $row): ?\DateTimeImmutable
    {
        foreach (['inventory_input_date', 'date', 'created_at'] as $key) {
            $raw = trim((string) ($row[$key] ?? ''));
            if ($raw === '') {
                continue;
            }

            try {
                return new \DateTimeImmutable(substr($raw, 0, 10));
            } catch (\Throwable $e) {
            }
        }

        return null;
    }

    private function extractDocumentCodeFromInventoryLabel(string $label): ?string
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }

        if (preg_match('/^(BC|BL|FA)\s+(.+)$/i', $label, $m)) {
            return trim((string) $m[2]);
        }

        return $label;
    }

    private function detectSourceTypeFromInventoryLabel(string $label): string
    {
        return preg_match('/^BC\b/i', trim($label)) ? 'purchase_order' : 'delivery_note';
    }

    private function extractProductIdFromInventoryDetail(array $detail): int
    {
        foreach (['product_id', 'inventory_input_detail_product_id', 'id_product'] as $key) {
            $value = isset($detail[$key]) ? (int) $detail[$key] : 0;
            if ($value > 0) {
                return $value;
            }
        }

        return 0;
    }

    private function extractLabelFromInventoryDetail(array $detail): string
    {
        foreach (['product_model', 'product_name', 'name', 'label', 'designation'] as $key) {
            $value = trim((string) ($detail[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function extractQuantityFromInventoryDetail(array $detail): int
    {
        foreach (['received', 'quantity', 'received_quantity', 'qty'] as $key) {
            if (isset($detail[$key]) && $detail[$key] !== '') {
                return max(1, (int) $detail[$key]);
            }
        }

        return 1;
    }

    private function extractBuyPriceFromInventoryDetail(array $detail): float
    {
        foreach (['unit_price', 'buy_price', 'product_supply_price', 'supply_price', 'price'] as $key) {
            if (isset($detail[$key]) && $detail[$key] !== '') {
                return (float) str_replace(',', '.', (string) $detail[$key]);
            }
        }

        return 0.0;
    }

    private function extractBarcodeFromInventoryDetail(array $detail): ?string
    {
        foreach (['product_barcode', 'barcode', 'ean', 'imei'] as $key) {
            $value = trim((string) ($detail[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractSkuFromInventoryDetail(array $detail): ?string
    {
        foreach (['products_ref_ext', 'sku', 'reference', 'ref', 'product_ref'] as $key) {
            $value = trim((string) ($detail[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractProductNameFromProduct(array $product): ?string
    {
        foreach (['product_model', 'product_name', 'name', 'label'] as $key) {
            $value = trim((string) ($product[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractBarcodeFromProduct(array $product): ?string
    {
        foreach (['product_barcode', 'barcode', 'ean', 'imei'] as $key) {
            $value = trim((string) ($product[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractSkuFromProduct(array $product): ?string
    {
        foreach (['products_ref_ext', 'sku', 'reference', 'ref', 'product_ref'] as $key) {
            $value = trim((string) ($product[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractBuyPriceFromProduct(array $product): float
    {
        foreach (['product_supply_price', 'buy_price', 'supply_price', 'unit_price'] as $key) {
            if (isset($product[$key]) && $product[$key] !== '') {
                return (float) str_replace(',', '.', (string) $product[$key]);
            }
        }

        return 0.0;
    }

    private function extractSellPriceFromProduct(array $product): float
    {
        foreach (['product_price', 'sell_price', 'price'] as $key) {
            if (isset($product[$key]) && $product[$key] !== '') {
                return (float) str_replace(',', '.', (string) $product[$key]);
            }
        }

        return 0.0;
    }

    private function extractCategoryIdFromProduct(array $product): ?int
    {
        foreach (['product_category', 'category_id'] as $key) {
            $value = isset($product[$key]) ? (int) $product[$key] : 0;
            if ($value > 0) {
                return $value;
            }
        }

        return null;
    }

    private function extractBrandIdFromProduct(array $product): ?int
    {
        foreach (['product_brand', 'brand_id'] as $key) {
            $value = isset($product[$key]) ? (int) $product[$key] : 0;
            if ($value > 0) {
                return $value;
            }
        }

        return null;
    }

    private function extractSupplierIdFromProduct(array $product): ?int
    {
        foreach (['product_supplier', 'supplier_id'] as $key) {
            $value = isset($product[$key]) ? (int) $product[$key] : 0;
            if ($value > 0) {
                return $value;
            }
        }

        return null;
    }

    private function buildCategoryMap(): array
    {
        $res = $this->hib->listCategories();
        $categories = is_array($res['data'] ?? null) ? $res['data'] : [];

        $byId = [];
        foreach ($categories as $c) {
            $id = (int) ($c['category_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $byId[$id] = [
                'id' => $id,
                'name' => trim((string) ($c['category_name'] ?? '')),
                'parent' => (int) ($c['category_id_parent'] ?? 0),
            ];
        }

        $map = [];
        foreach ($byId as $id => $row) {
            $parts = [];
            $current = $row;
            $guard = 0;

            while ($current && $guard < 10) {
                if (($current['name'] ?? '') !== '') {
                    array_unshift($parts, $current['name']);
                }

                $parentId = (int) ($current['parent'] ?? 0);
                $current = $parentId > 0 && isset($byId[$parentId]) ? $byId[$parentId] : null;
                $guard++;
            }

            $map[$id] = $parts ? implode(' - ', $parts) : null;
        }

        return $map;
    }

    private function buildBrandMap(): array
    {
        $res = $this->hib->listBrands();
        $brands = is_array($res['data'] ?? null) ? $res['data'] : [];

        $map = [];
        foreach ($brands as $b) {
            $id = (int) ($b['brand_id'] ?? 0);
            $name = trim((string) ($b['brand_name'] ?? ''));

            if ($id > 0 && $name !== '') {
                $map[$id] = $name;
            }
        }

        return $map;
    }

    private function buildSupplierMap(): array
    {
        $res = $this->hib->listSuppliers();
        $suppliers = is_array($res['data'] ?? null) ? $res['data'] : [];

        $map = [];
        foreach ($suppliers as $s) {
            $id = (int) ($s['supplier_id'] ?? 0);
            $name = trim((string) ($s['supplier_name'] ?? ''));

            if ($id > 0 && $name !== '') {
                $map[$id] = $name;
            }
        }

        return $map;
    }

    private function readVatChoiceFromProduct(array $product): ?string
    {
        if (isset($product['product_vat_value']) && $product['product_vat_value'] !== '') {
            $vatValue = (float) $product['product_vat_value'];

            if (abs($vatValue - 20.0) < 0.01) {
                return '20';
            }

            if (abs($vatValue - 0.0) < 0.01) {
                return '0';
            }
        }

        $vatCode = trim((string) ($product['product_vat'] ?? ''));
        if ($vatCode === '1') {
            return '20';
        }
        if ($vatCode === '5') {
            return '0';
        }
        if ($vatCode === '20' || $vatCode === '0') {
            return $vatCode;
        }

        return null;
    }

    private function readAccountingAccountFromProduct(array $product): ?string
    {
        $account = trim((string) ($product['accounting_account'] ?? ''));
        return $account !== '' ? $account : null;
    }

    #[Route('/create-from-rachats', name: 'create_from_rachats', methods: ['POST'])]
    public function createFromRachats(Request $req): Response
    {
        $ids = $req->request->all('ids');
        if (!$ids) {
            $this->addFlash('error', 'Sélection requise.');
            return $this->redirectToRoute('admin_rachats_index');
        }

        $repo = $this->em->getRepository(Rachat::class);
        $rachats = [];
        foreach ($ids as $id) {
            $r = $repo->find((int) $id);
            if ($r) {
                $rachats[] = $r;
            }
        }

        if (!$rachats) {
            $this->addFlash('error', 'Rachats introuvables.');
            return $this->redirectToRoute('admin_rachats_index');
        }

        $first = $rachats[0];
        $supplierId = (int) $first->getHibSupplierId();
        $nom = (string) $first->getNom();
        $prenom = (string) $first->getPrenom();

        foreach ($rachats as $r) {
            if ((int) $r->getHibSupplierId() !== $supplierId) {
                $this->addFlash('error', 'Sélection invalide : plusieurs revendeurs (suppliers) différents.');
                return $this->redirectToRoute('admin_rachats_index');
            }
        }

        $isMulti = count($rachats) > 1;
        $inv = $this->hib->ensureDailyRachatInput(1, $supplierId, $nom, $prenom, $isMulti);

        if (!($inv['ok'] ?? false)) {
            $this->addFlash('error', 'Erreur création/récup arrivage.');
            return $this->redirectToRoute('admin_rachats_index');
        }

        $inventoryInputId = (int) ($inv['data']['inventory_input_id'] ?? $inv['data']['id'] ?? $inv['id'] ?? 0);
        if ($inventoryInputId <= 0) {
            $this->addFlash('error', 'Impossible de récupérer l’ID de l’arrivage Hiboutik.');
            return $this->redirectToRoute('admin_rachats_index');
        }

        $details = $this->hib->listInventoryInputDetails($inventoryInputId);
        $already = [];
        foreach (($details['data'] ?? []) as $d) {
            $pid = (int) ($d['product_id'] ?? 0);
            if ($pid) {
                $already[$pid] = true;
            }
        }

        $added = 0;
        foreach ($rachats as $r) {
            $hibProductId = (int) $r->getHibProductId();
            if ($hibProductId <= 0) {
                continue;
            }

            if (isset($already[$hibProductId])) {
                continue;
            }

            $res = $this->hib->addProductToInventoryInput($inventoryInputId, $hibProductId, 1);
            if (($res['ok'] ?? false)) {
                $added++;
                $already[$hibProductId] = true;
            }
        }

        $this->addFlash('success', sprintf(
            '%s : arrivage #%d (%s) — %d produit(s) ajouté(s).',
            ($inv['created'] ?? false) ? 'Créé' : 'Réutilisé',
            $inventoryInputId,
            $inv['label'] ?? '',
            $added
        ));

        return $this->redirectToRoute('admin_arrivages_index');
    }
}