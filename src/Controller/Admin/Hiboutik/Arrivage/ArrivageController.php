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
use App\Service\CacheApiClient;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Route('/admin/arrivages', name: 'admin_arrivages_')]
final class ArrivageController extends AbstractController
{
    public function __construct(
        private HiboutikClient $hib,
        private EntityManagerInterface $em,
        private CacheApiClient $cacheApi,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
public function index(): Response
{
    $arrivages = $this->hib->listAllInventoryInputs();
    $supplierMap = $this->buildSupplierMap();

    $rows = [];

    foreach ($arrivages as $a) {
        if (!is_array($a)) {
            continue;
        }

        $id = (int) ($a['inventory_input_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $detailsRes = $this->hib->listInventoryInputDetails($id);
        $details = is_array($detailsRes['data'] ?? null) ? $detailsRes['data'] : [];

        $lineCount = count($details);
        $receivedCount = 0;
        $partial = false;
        $productNames = [];

        foreach ($details as $d) {
            if (!is_array($d)) {
                continue;
            }

            $qty = (int) ($d['quantity'] ?? 0);
            $received = (int) ($d['received'] ?? 0);

            if ($received >= 1) {
                $receivedCount++;
            }

            if ($received > 0 && $received < $qty) {
                $partial = true;
            }

            $name = trim((string) ($d['product_model'] ?? ''));
            if ($name !== '') {
                $productNames[] = $name;
            }
        }

        $supplierId = (int) ($a['inventory_input_supplier_id'] ?? 0);

        $state = 'vide';
        if ($lineCount > 0 && $receivedCount === 0) {
            $state = 'non réceptionné';
        } elseif ($lineCount > 0 && $receivedCount < $lineCount) {
            $state = 'partiellement réceptionné';
        } elseif ($lineCount > 0 && $receivedCount === $lineCount) {
            $state = 'réceptionné';
        }

        if ($partial) {
            $state = 'partiellement réceptionné';
        }

        $rows[] = [
            'id' => $id,
            'label' => (string) ($a['inventory_input_label'] ?? ''),
            'date' => (string) ($a['inventory_input_date'] ?? ''),
            'supplier_id' => $supplierId,
            'supplier_name' => $supplierMap[$supplierId] ?? ('Supplier #' . $supplierId),
            'quantity' => (int) ($a['inventory_input_quantity'] ?? 0),
            'amount' => (string) ($a['inventory_input_amount'] ?? ''),
            'line_count' => $lineCount,
            'received_count' => $receivedCount,
            'state' => $state,
            'product_names' => array_slice(array_values(array_unique($productNames)), 0, 4),
            'raw' => $a,
            'can_delete' => $lineCount === 0,
            'invoice_number' => (string) ($a['supplier_invoice_number'] ?? ''),
        ];
    }

    return $this->render('@SyliusAdmin/Arrivages/index.html.twig', [
        'arrivages' => $rows,
        'suppliers' => $this->hib->listSuppliers()['data'] ?? [],

    ]);
}

#[Route('/details/{detailId}/update', name: 'update_detail', methods: ['POST'])]
public function updateDetail(int $detailId, Request $request): Response
{
    $inventoryInputId = (int) $request->request->get('inventory_input_id', 0);

    if (!$this->isCsrfTokenValid('arrivage_update_detail_' . $detailId, (string) $request->request->get('_csrf_token'))) {
        throw $this->createAccessDeniedException('CSRF invalid');
    }

    $quantity = max(1, (int) $request->request->get('quantity', 1));
    $productPrice = trim((string) $request->request->get('product_price', ''));
    $serialNumber = trim((string) $request->request->get('product_serial_number', ''));

    $errors = [];

    $r1 = $this->hib->updateInventoryInputDetailAttribute($detailId, 'quantity', $quantity);
    if (!($r1['ok'] ?? false)) {
        $errors[] = 'quantité';
    }

    if ($productPrice !== '') {
        $normalizedPrice = number_format((float) str_replace(',', '.', $productPrice), 2, '.', '');
        $r2 = $this->hib->updateInventoryInputDetailAttribute($detailId, 'product_price', $normalizedPrice);
        if (!($r2['ok'] ?? false)) {
            $errors[] = 'prix achat';
        }
    }

    $r3 = $this->hib->updateInventoryInputDetailAttribute($detailId, 'product_serial_number', $serialNumber);
    if (!($r3['ok'] ?? false)) {
        $errors[] = 'serial';
    }

    if ($errors) {
        $dbg = $this->hib->getLastDebug();
        $this->addFlash(
            'error',
            'Erreur mise à jour ligne (' . implode(', ', $errors) . ') : ' . substr((string) ($dbg['raw'] ?? ''), 0, 300)
        );
    } else {
        $this->addFlash('success', "Ligne #$detailId mise à jour.");
    }

    return $this->redirectToRoute('admin_arrivages_details', ['id' => $inventoryInputId]);
}



    #[Route('/rachats', name: 'rachats', methods: ['GET'])]
    public function rachats(): Response
    {
        $arrivages = $this->hib->listRachatInputs();

        return $this->render('@SyliusAdmin/Arrivages/index.html.twig', [
            'arrivages' => $arrivages,
        ]);
    }

    public function updateInventoryInputAttribute(int $inventoryInputId, string $attribute, string|int $value): array
{
    return $this->req('PUT', 'inventory_inputs/' . $inventoryInputId, [
        'headers' => [
            'Accept' => '*/*',
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'inventory_input_attribute' => $attribute,
            'new_value' => (string) $value,
        ],
    ]);
}


#[Route('/{id}/update', name: 'update', methods: ['POST'])]
public function update(int $id, Request $request): Response
{
    if (!$this->isCsrfTokenValid('arrivage_update_' . $id, (string) $request->request->get('_csrf_token'))) {
        throw $this->createAccessDeniedException('CSRF invalid');
    }

    $label = trim((string) $request->request->get('label', ''));
    $supplierInvoiceNumber = trim((string) $request->request->get('supplier_invoice_number', ''));

    $errors = [];

    if ($label !== '') {
        $r1 = $this->hib->updateInventoryInputAttribute($id, 'inventory_input_label', $label);
        if (!($r1['ok'] ?? false)) {
            $errors[] = 'libellé';
        }
    }

    $r2 = $this->hib->updateInventoryInputAttribute($id, 'supplier_invoice_number', $supplierInvoiceNumber);
    if (!($r2['ok'] ?? false)) {
        $errors[] = 'n° facture fournisseur';
    }

    if ($errors) {
        $dbg = $this->hib->getLastDebug();
        $this->addFlash(
            'error',
            'Erreur mise à jour arrivage (' . implode(', ', $errors) . ') : ' . substr((string) ($dbg['raw'] ?? ''), 0, 300)
        );
    } else {
        $this->addFlash('success', "Arrivage #$id mis à jour.");
    }

    return $this->redirectToRoute('admin_arrivages_details', ['id' => $id]);
}

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
public function delete(int $id, Request $request): Response
{
    if (!$this->isCsrfTokenValid('arrivage_delete_' . $id, (string) $request->request->get('_csrf_token'))) {
        throw $this->createAccessDeniedException('CSRF invalid');
    }

    $detailsRes = $this->hib->listInventoryInputDetails($id);
    $details = is_array($detailsRes['data'] ?? null) ? $detailsRes['data'] : [];

    if (count($details) > 0) {
        $this->addFlash('error', 'Suppression refusée : l’arrivage n’est pas vide.');
        return $this->redirectToRoute('admin_arrivages_details', ['id' => $id]);
    }

    $res = $this->hib->deleteInventoryInput($id);

    if (!($res['ok'] ?? false)) {
        $dbg = $this->hib->getLastDebug();
        $this->addFlash('error', 'Erreur suppression arrivage : ' . substr((string) ($dbg['raw'] ?? ''), 0, 300));
    } else {
        $this->addFlash('success', "Arrivage #$id supprimé.");
    }

    return $this->redirectToRoute('admin_arrivages_index');
}

  #[Route('/create', name: 'create', methods: ['POST'])]
public function create(Request $request): Response
{
    if (!$this->isCsrfTokenValid('arrivage_create', (string) $request->request->get('_csrf_token'))) {
        throw $this->createAccessDeniedException('CSRF invalid');
    }

    $label = trim((string) $request->request->get('label', ''));
    $supplierId = (int) $request->request->get('supplier_id', 0);
    $supplierInvoiceNumber = trim((string) $request->request->get('supplier_invoice_number', ''));

    if ($label === '') {
        $this->addFlash('error', 'Libellé obligatoire.');
        return $this->redirectToRoute('admin_arrivages_index');
    }

    if ($supplierId <= 0) {
        $this->addFlash('error', 'Fournisseur obligatoire.');
        return $this->redirectToRoute('admin_arrivages_index');
    }

    $storeMeta = $this->hib->getDefaultStoreMeta();
    $stockId = (int) ($storeMeta['stock_id'] ?? 1);

    $res = $this->hib->createInventoryInput($stockId, $supplierId, $label);

    if (!($res['ok'] ?? false)) {
        $dbg = $this->hib->getLastDebug();
        $this->addFlash(
            'error',
            'Erreur création arrivage Hiboutik (' . ($res['status'] ?? '??') . ') : ' . substr((string) ($dbg['raw'] ?? ''), 0, 300)
        );
        return $this->redirectToRoute('admin_arrivages_index');
    }

    $inventoryInputId = (int) ($res['id'] ?? 0);

    if ($inventoryInputId > 0 && $supplierInvoiceNumber !== '') {
        $upd = $this->hib->updateInventoryInputAttribute(
            $inventoryInputId,
            'supplier_invoice_number',
            $supplierInvoiceNumber
        );

        if (!($upd['ok'] ?? false)) {
            $dbg = $this->hib->getLastDebug();
            $this->addFlash(
                'warning',
                'Arrivage créé, mais impossible de renseigner le n° de facture : ' . substr((string) ($dbg['raw'] ?? ''), 0, 300)
            );
        }
    }

    $this->addFlash('success', 'Arrivage créé.');

    return $this->redirectToRoute('admin_arrivages_details', [
        'id' => $inventoryInputId,
    ]);
}

   #[Route('/details/{id}', name: 'details', methods: ['GET'])]
    public function details(int $id): Response
    {
        $arrivage = $this->findInventoryInputRowById($id);
        if (!$arrivage) {
            $this->addFlash('error', 'Arrivage introuvable.');
            return $this->redirectToRoute('admin_arrivages_index');
        }

        $res = $this->hib->listInventoryInputDetails($id);
        $details = is_array($res['data'] ?? null) ? $res['data'] : [];

        $supplierMap = $this->buildSupplierMap();
        $supplierId = (int) ($arrivage['inventory_input_supplier_id'] ?? 0);
        $supplierName = $supplierMap[$supplierId] ?? ('Supplier #' . $supplierId);

        $productCache = [];

foreach ($details as &$row) {
    $pid = (int) ($row['product_id'] ?? 0);
    $row['_product_thumb'] = null;
    $row['_product_admin_url'] = null;

    if ($pid <= 0) {
        continue;
    }

    if (!array_key_exists($pid, $productCache)) {
        $found = null;

        $search = $this->cacheApi->searchAdminProducts([
            'q' => (string) $pid,
            'limit' => 20,
            'offset' => 0,
            'include_archived' => '1',
            'include_hidden' => '1',
        ]);

        $products = is_array($search['data'] ?? null) ? $search['data'] : [];

        foreach ($products as $p) {
            if ((int) ($p['product_id'] ?? 0) === $pid) {
                $found = $p;
                break;
            }
        }

        $productCache[$pid] = is_array($found) ? $found : [];
    }

    $p = $productCache[$pid];
    $mini = '';

    if (is_array($p['images'] ?? null)) {
        foreach ($p['images'] as $img) {
            if (
                is_array($img)
                && isset($img['image_name'], $img['url'])
                && str_starts_with((string) $img['image_name'], 'mini_')
            ) {
                $mini = (string) $img['url'];
                break;
            }
        }
    }

    if ($mini === '') {
        $mini = (string) ($p['thumb'] ?? '');
    }
    if ($mini === '') {
        $mini = (string) ($p['image'] ?? '');
    }

    $row['_product_thumb'] = $mini !== '' ? $mini : null;
}
unset($row);

        return $this->render('@SyliusAdmin/Arrivages/details.html.twig', [
            'id' => $id,
            'arrivage' => $arrivage,
            'details' => $details,
            'supplierName' => $supplierName,
            'canDelete' => count($details) === 0,
            'suppliers' => $this->hib->listSuppliers()['data'] ?? [],
        ]);
    }

    #[Route('/{id}/add-product', name: 'add_product', methods: ['POST'])]
    public function addProduct(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('arrivage_add_product_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRF invalid');
        }

        $productId = (int) $request->request->get('product_id', 0);
        $quantity = max(1, (int) $request->request->get('quantity', 1));
        $buyPriceRaw = (string) $request->request->get('buy_price', '');
        $buyPrice = $buyPriceRaw !== '' ? (float) str_replace(',', '.', $buyPriceRaw) : null;

        if ($productId <= 0) {
            $this->addFlash('error', 'Produit invalide.');
            return $this->redirectToRoute('admin_arrivages_details', ['id' => $id]);
        }

        $res = $this->hib->addProductToInventoryInput($id, $productId, $quantity, $buyPrice);

        if (!($res['ok'] ?? false)) {
            $dbg = $this->hib->getLastDebug();
            $this->addFlash(
                'error',
                'Erreur ajout produit (' . ($res['status'] ?? '??') . ') : ' . substr((string)($dbg['raw'] ?? ''), 0, 300)
            );
        } else {
            $this->addFlash('success', "Produit #$productId ajouté à l’arrivage #$id.");
        }

        return $this->redirectToRoute('admin_arrivages_details', ['id' => $id]);
    }

    #[Route('/details/{detailId}/receive', name: 'receive_detail', methods: ['POST'])]
    public function receiveDetail(int $detailId, Request $request): Response
    {
        $inventoryInputId = (int) $request->request->get('inventory_input_id', 0);

        if (!$this->isCsrfTokenValid('arrivage_receive_detail_' . $detailId, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRF invalid');
        }

        if ($inventoryInputId <= 0) {
            $this->addFlash('error', 'Arrivage invalide.');
            return $this->redirectToRoute('admin_arrivages_index');
        }

        $res = $this->hib->receiveInventoryInputDetail($detailId, 1);

        if (!($res['ok'] ?? false)) {
            $dbg = $this->hib->getLastDebug();
            $this->addFlash(
                'error',
                'Erreur réception ligne (' . ($res['status'] ?? '??') . ') : ' . substr((string)($dbg['raw'] ?? ''), 0, 300)
            );
        } else {
            $this->addFlash('success', "Ligne #$detailId réceptionnée.");
        }

        return $this->redirectToRoute('admin_arrivages_details', ['id' => $inventoryInputId]);
    }

    #[Route('/details/{detailId}/unreceive', name: 'unreceive_detail', methods: ['POST'])]
public function unreceiveDetail(int $detailId, Request $request): Response
{
    $inventoryInputId = (int) $request->request->get('inventory_input_id', 0);

    if (!$this->isCsrfTokenValid('arrivage_unreceive_detail_' . $detailId, (string) $request->request->get('_csrf_token'))) {
        throw $this->createAccessDeniedException('CSRF invalid');
    }

    $res = $this->hib->receiveInventoryInputDetail($detailId, 0);

    if (!($res['ok'] ?? false)) {
        $dbg = $this->hib->getLastDebug();
        $this->addFlash('error', 'Erreur dé-réception ligne : ' . substr((string) ($dbg['raw'] ?? ''), 0, 300));
    } else {
        $this->addFlash('success', "Ligne #$detailId dé-réceptionnée.");
    }

    return $this->redirectToRoute('admin_arrivages_details', ['id' => $inventoryInputId]);
}

#[Route('/details/{detailId}/delete', name: 'delete_detail', methods: ['POST'])]
public function deleteDetail(int $detailId, Request $request): Response
{
    $inventoryInputId = (int) $request->request->get('inventory_input_id', 0);

    if (!$this->isCsrfTokenValid('arrivage_delete_detail_' . $detailId, (string) $request->request->get('_csrf_token'))) {
        throw $this->createAccessDeniedException('CSRF invalid');
    }

    $res = $this->hib->deleteInventoryInputDetail($detailId);

    if (!($res['ok'] ?? false)) {
        $dbg = $this->hib->getLastDebug();
        $this->addFlash('error', 'Erreur suppression ligne : ' . substr((string) ($dbg['raw'] ?? ''), 0, 300));
    } else {
        $this->addFlash('success', "Ligne #$detailId supprimée.");
    }

    return $this->redirectToRoute('admin_arrivages_details', ['id' => $inventoryInputId]);
}

    #[Route('/validate/{id}', name: 'validate', methods: ['POST'])]
    public function validate(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('arrivage_validate_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRF invalid');
        }

        $res = $this->hib->validateInventoryInput($id);

        if (!($res['ok'] ?? false)) {
            $dbg = $this->hib->getLastDebug();
            $this->addFlash(
                'error',
                'Erreur Hiboutik (' . ($res['status'] ?? '??') . ') : ' . substr((string)($dbg['raw'] ?? ''), 0, 300)
            );

            return $this->redirectToRoute('admin_arrivages_details', ['id' => $id]);
        }

        $this->addFlash('success', "Arrivage #$id validé !");

        return $this->redirectToRoute('admin_arrivages_details', ['id' => $id]);
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
        foreach (['product_price', 'unit_price', 'buy_price', 'product_supply_price', 'supply_price', 'price'] as $key) {
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

    #[Route('/product-search', name: 'product_search', methods: ['GET'])]
public function productSearch(Request $request): JsonResponse
{
    $q = trim((string) $request->query->get('q', ''));

    if (mb_strlen($q) < 2) {
        return $this->json([
            'ok' => true,
            'items' => [],
        ]);
    }

    $search = $this->cacheApi->searchAdminProducts([
        'q' => $q,
        'include_archived' => '0',
        'include_hidden' => '1',
        'limit' => 12,
        'offset' => 0,
    ]);

    $products = is_array($search['data'] ?? null) ? $search['data'] : [];
    $items = [];

    foreach ($products as $p) {
        if (!is_array($p)) {
            continue;
        }

        $productId = (int) ($p['product_id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }

        $name = trim((string) (
            $p['product_model']
            ?? $p['product_name']
            ?? $p['name']
            ?? ''
        ));

        $barcode = trim((string) ($p['product_barcode'] ?? ''));
        $sku = trim((string) ($p['products_ref_ext'] ?? ''));
        $price = isset($p['product_supply_price']) ? (string) $p['product_supply_price'] : '';
        $stock = 0;

        if (is_array($p['stock_available'] ?? null)) {
            foreach ($p['stock_available'] as $stockRow) {
                $stock += (int) ($stockRow['stock_available'] ?? 0);
            }
        } else {
            $stock = (int) ($p['stock_available'] ?? 0);
        }

        $items[] = [
            'id' => $productId,
            'label' => sprintf(
                '#%d — %s%s%s%s',
                $productId,
                $name !== '' ? $name : 'Sans nom',
                $barcode !== '' ? ' — code: ' . $barcode : '',
                $sku !== '' ? ' — ref: ' . $sku : '',
                $price !== '' ? ' — PA: ' . $price : ''
            ),
            'name' => $name,
            'barcode' => $barcode,
            'sku' => $sku,
            'buy_price' => $price,
            'stock' => $stock,
        ];
    }

    return $this->json([
        'ok' => true,
        'items' => $items,
    ]);
}
}