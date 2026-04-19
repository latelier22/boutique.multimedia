<?php

namespace App\Controller\Admin\Hiboutik;

use App\Entity\Hiboutik\MobileImportRow;
use App\Entity\Hiboutik\MobileImportSession;
use App\Service\CacheApiClient;
use App\Service\Hiboutik\MobileImportParser;
use App\Service\Hiboutik\MobileImportProductCreator;
use App\Service\HiboutikClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/hiboutik/mobile-import', name: 'admin_hib_mobile_import_')]
class MobileImportController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private HiboutikClient $hib,
        private CacheApiClient $cacheApi,
        private MobileImportParser $parser,
        private MobileImportProductCreator $creator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $sessions = $this->em->getRepository(MobileImportSession::class)->findBy([], ['id' => 'DESC']);

        return $this->render('@SyliusAdmin/Hiboutik/MobileImport/index.html.twig', [
            'sessions' => $sessions,
        ]);
    }

    #[Route('/upload', name: 'upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('hib_mobile_import_upload', (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('CSRF invalid');
            }

            $file = $request->files->get('file');
            if (!$file) {
                $this->addFlash('error', 'Fichier manquant.');
                return $this->redirectToRoute('admin_hib_mobile_import_upload');
            }

            $uploadDir = $this->getParameter('kernel.project_dir') . '/var/mobile-imports';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0775, true);
            }

            $storedFilename = uniqid('mobile_import_', true) . '.' . ($file->guessExtension() ?: 'xlsx');

            try {
                $file->move($uploadDir, $storedFilename);
            } catch (FileException $e) {
                $this->addFlash('error', 'Impossible de stocker le fichier : ' . $e->getMessage());
                return $this->redirectToRoute('admin_hib_mobile_import_upload');
            }

            $fullPath = $uploadDir . '/' . $storedFilename;

            try {
                $parsed = $this->parser->parse($fullPath);
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Erreur lecture fichier : ' . $e->getMessage());
                return $this->redirectToRoute('admin_hib_mobile_import_upload');
            }

            $session = new MobileImportSession();
            $session->setSourceFilename($file->getClientOriginalName() ?: $storedFilename);
            $session->setStoredFilename($storedFilename);
            $session->setSourceType((string) ($parsed['source_type'] ?? 'delivery_note'));
            $session->setDocumentCode($parsed['document_code'] ?? null);
            $session->setMeta((array) ($parsed['meta'] ?? []));
            $session->setStatus('draft');

            foreach ((array) ($parsed['rows'] ?? []) as $rowData) {
                $row = new MobileImportRow();
                $row->setLineNumber((int) ($rowData['line_number'] ?? 0));
                $row->setUnitIndex(isset($rowData['unit_index']) ? (int) $rowData['unit_index'] : null);
                $row->setSku($rowData['sku'] ?? null);
                $row->setEan($rowData['ean'] ?? null);
                $row->setRawLabel((string) ($rowData['raw_label'] ?? ''));
                $row->setImei($rowData['imei'] ?? null);
                $row->setQuantity((int) ($rowData['quantity'] ?? 1));
                $row->setBuyPrice((float) ($rowData['buy_price'] ?? 0));
                $row->setCurrencyCode($rowData['currency_code'] ?? 'EUR');
                $row->setParsedBrand($rowData['parsed_brand'] ?? null);
                $row->setParsedModel($rowData['parsed_model'] ?? null);
                $row->setParsedStorage($rowData['parsed_storage'] ?? null);
                $row->setParsedColor($rowData['parsed_color'] ?? null);
                $row->setParsedGrade($rowData['parsed_grade'] ?? null);
                $row->setRequiresImei((bool) ($rowData['requires_imei'] ?? false));
                $row->setStatus((string) ($rowData['status'] ?? 'draft'));
                $row->setRawData((array) ($rowData['raw_data'] ?? []));
                $session->addRow($row);
            }

            $this->em->persist($session);
            $this->em->flush();

            $this->addFlash('success', 'Fichier importé. Session #' . $session->getId());

            return $this->redirectToRoute('admin_hib_mobile_import_show', [
                'id' => $session->getId(),
            ]);
        }

        return $this->render('@SyliusAdmin/Hiboutik/MobileImport/upload.html.twig');
    }

   #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
public function show(int $id): Response
{
    /** @var MobileImportSession|null $session */
    $session = $this->em->getRepository(MobileImportSession::class)->find($id);
    if (!$session) {
        throw $this->createNotFoundException('Session introuvable');
    }

    $suppliersRes = $this->hib->listSuppliers();
    $suppliers = is_array($suppliersRes['data'] ?? null) ? $suppliersRes['data'] : [];
    $suppliers = array_values(array_filter($suppliers, static function (array $supplier): bool {
        return (int) ($supplier['supplier_enabled'] ?? 0) === 1;
    }));

    $categoriesRes = $this->hib->listCategories();
    $categories = is_array($categoriesRes['data'] ?? null) ? $categoriesRes['data'] : [];

    $brandsRes = $this->hib->listBrands();
    $brands = is_array($brandsRes['data'] ?? null) ? $brandsRes['data'] : [];

    return $this->render('@SyliusAdmin/Hiboutik/MobileImport/show.html.twig', [
        'session' => $session,
        'suppliers' => $suppliers,
        'categories' => $categories,
        'brands' => $brands,
        'rowsView' => $this->buildRowsView($session),
    ]);
}

    #[Route('/{id}/prepare-arrival', name: 'prepare_arrival', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function prepareArrival(int $id, Request $request): Response
    {
        /** @var MobileImportSession|null $session */
        $session = $this->em->getRepository(MobileImportSession::class)->find($id);
        if (!$session) {
            throw $this->createNotFoundException('Session introuvable');
        }

        if (!$this->isCsrfTokenValid('hib_mobile_prepare_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRF invalid');
        }

        $supplierId = (int) $request->request->get('supplier_id');
        $documentCode = trim((string) $request->request->get('document_code', ''));

        if ($supplierId <= 0) {
            $this->addFlash('error', 'Fournisseur obligatoire.');
            return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $id]);
        }

        if ($documentCode === '') {
            $this->addFlash('error', 'Code BL / commande obligatoire.');
            return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $id]);
        }

        $meta = $this->hib->getDefaultStoreMeta();
        $stockId = (int) ($meta['stock_id'] ?? 1);

        $label = sprintf(
            '%s %s',
            $session->getSourceType() === 'delivery_note' ? 'BL' : 'BC',
            $documentCode
        );

        $res = $this->hib->createInventoryInput($stockId, $supplierId, $label);

        if (!($res['ok'] ?? false) || empty($res['id'])) {
            $this->addFlash('error', 'Impossible de créer l’arrivage Hiboutik.');
            return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $id]);
        }

        $session->setSupplierId($supplierId);
        $session->setDocumentCode($documentCode);
        $session->setHibInventoryInputId((int) $res['id']);
        $session->setStatus('prepared');

        $this->em->flush();

        $this->addFlash('success', 'Arrivage Hiboutik créé : #' . $res['id']);

        return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $id]);
    }

    #[Route('/row/{rowId}/set-imei', name: 'set_imei', requirements: ['rowId' => '\d+'], methods: ['POST'])]
    public function setImei(int $rowId, Request $request): Response
    {
        /** @var MobileImportRow|null $row */
        $row = $this->em->getRepository(MobileImportRow::class)->find($rowId);
        if (!$row) {
            throw $this->createNotFoundException('Ligne introuvable');
        }

        if (!$this->isCsrfTokenValid('hib_mobile_set_imei_' . $rowId, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRF invalid');
        }

        // -----------------------------------------------------------------
        // Ici, on ne fait PAS de validation métier stricte.
        // On enregistre ce que l'utilisateur met dans le champ.
        // Attention :
        // l'entity MobileImportRow::setImei() nettoie encore en digits-only.
        // -----------------------------------------------------------------
        $imei = trim((string) $request->request->get('imei', ''));
        if ($imei === '') {
            $this->addFlash('error', 'Valeur vide.');
            return $this->redirectToRoute('admin_hib_mobile_import_show', [
                'id' => $row->getSession()?->getId(),
            ]);
        }

        $row->setImei($imei);
        $row->setStatus('draft');

        $this->em->flush();

        $this->addFlash('success', 'Valeur barcode/IMEI enregistrée.');

        return $this->redirectToRoute('admin_hib_mobile_import_show', [
            'id' => $row->getSession()?->getId(),
        ]);
    }

    #[Route('/row/{rowId}/import', name: 'import_row', requirements: ['rowId' => '\d+'], methods: ['POST'])]
    public function importRow(int $rowId, Request $request): Response
    {
        /** @var MobileImportRow|null $row */
        $row = $this->em->getRepository(MobileImportRow::class)->find($rowId);
        if (!$row) {
            throw $this->createNotFoundException('Ligne introuvable');
        }

        if (!$this->isCsrfTokenValid('hib_mobile_import_row_' . $rowId, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRF invalid');
        }

        $session = $row->getSession();
        if (!$session) {
            throw $this->createNotFoundException('Session introuvable');
        }

        if (!$session->getHibInventoryInputId()) {
            $this->addFlash('error', 'Créer d’abord l’arrivage Hiboutik.');
            return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $session->getId()]);
        }

        $res = $this->creator->findOrCreateFromRow($row, $session->getHibInventoryInputId());

        if (!($res['ok'] ?? false)) {
            $row->setStatus('error');
            $row->setErrorMessage($this->humanizeImportError($res));
            $this->em->flush();

            $this->addFlash('error', 'Import KO : ' . $row->getErrorMessage());

            return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $session->getId()]);
        }

        $row->setCreatedProductId((int) $res['product_id']);
        $row->setStatus('imported');
        $row->setErrorMessage(null);

        $this->em->flush();

        $mode = (string) ($res['mode'] ?? '');
        if ($mode === 'existing') {
            $this->addFlash('success', 'Produit existant réutilisé #' . $res['product_id']);
        } else {
            $this->addFlash('success', 'Produit créé #' . $res['product_id']);
        }

        return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $session->getId()]);
    }

    #[Route('/prepa/{id}', name: 'prepa', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function prepa(int $id): Response
    {
        // ------------------------------------------------------------
        // On garde la route pour compatibilité, mais on renvoie
        // simplement vers la page show unique.
        // ------------------------------------------------------------
        return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $id]);
    }

    #[Route('/{id}/import-all', name: 'import_all', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function importAll(int $id, Request $request): Response
    {
        /** @var MobileImportSession|null $session */
        $session = $this->em->getRepository(MobileImportSession::class)->find($id);
        if (!$session) {
            throw $this->createNotFoundException('Session introuvable');
        }

        if (!$this->isCsrfTokenValid('hib_mobile_import_all_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRF invalid');
        }

        if (!$session->getHibInventoryInputId()) {
            $this->addFlash('error', 'Aucun arrivage Hiboutik associé à cette session.');
            return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $id]);
        }

        $ok = 0;
        $ko = 0;

        foreach ($session->getRows() as $row) {
            if ($row->getCreatedProductId()) {
                continue;
            }

            $res = $this->creator->findOrCreateFromRow($row, $session->getHibInventoryInputId());

            if ($res['ok'] ?? false) {
                $row->setCreatedProductId((int) $res['product_id']);
                $row->setStatus('imported');
                $row->setErrorMessage(null);
                $ok++;
            } else {
                $row->setStatus('error');
                $row->setErrorMessage($this->humanizeImportError($res));
                $ko++;
            }
        }

        $this->em->flush();

        $this->addFlash('success', sprintf('Import terminé : %d OK / %d KO', $ok, $ko));

        return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $id]);
    }

    /**
     * Prépare les données d'affichage par ligne.
     * On veut visualiser :
     * - barcode visé
     * - SKU visé
     */
    private function buildRowsView(MobileImportSession $session): array
    {
        $out = [];

        foreach ($session->getRows() as $row) {
            $barcode = trim((string) ($row->getImei() ?: $row->getEan() ?: ''));
            $sku = trim((string) $row->getSku());

            $out[] = [
                'row' => $row,
                'wantedBarcode' => $barcode !== '' ? $barcode : null,
                'wantedSku' => $sku !== '' ? $sku : null,
            ];
        }

        return $out;
    }

    /**
     * Transforme les erreurs techniques en messages lisibles.
     */
 private function humanizeImportError(array $res): string
{
    $error = (string) ($res['error'] ?? 'import_failed');

    return match ($error) {
        'barcode_sku_conflict' =>
            sprintf(
                'Conflit : le barcode correspond au produit #%s et le SKU au produit #%s.',
                (string) ($res['barcode_product_id'] ?? '?'),
                (string) ($res['sku_product_id'] ?? '?'),
            ),

        'imei_already_exists' =>
            sprintf(
                'IMEI déjà présent sur le produit #%s : import interdit.',
                (string) ($res['existing_product_id'] ?? '?')
            ),

        'inventory_add_failed_existing_product' =>
            'Produit existant trouvé, mais ajout dans l’arrivage impossible.',

        'inventory_add_failed_created_product' =>
            'Produit créé, mais ajout dans l’arrivage impossible.',

        'product_create_failed' =>
            'Création Hiboutik impossible pour cette ligne.',

        'existing_product_invalid' =>
            'Le produit existant trouvé est invalide.',
        'product_post_update_failed' =>
    'Produit créé, mais mise à jour complémentaire Hiboutik impossible.',
    'no_created_product_id' =>
    'Aucun produit lié à cette ligne.',

'product_reapply_failed' =>
    'Mise à jour du produit existant impossible.',

        default => $error,
    };
}

#[Route('/{id}/save-grid', name: 'save_grid', requirements: ['id' => '\d+'], methods: ['POST'])]
public function saveGrid(int $id, Request $request): Response
{
    /** @var MobileImportSession|null $session */
    $session = $this->em->getRepository(MobileImportSession::class)->find($id);
    if (!$session) {
        throw $this->createNotFoundException('Session introuvable');
    }

    if (!$this->isCsrfTokenValid('hib_mobile_save_grid_' . $id, (string) $request->request->get('_csrf_token'))) {
        throw $this->createAccessDeniedException('CSRF invalid');
    }

    $rowsData = (array) $request->request->all('rows');

    foreach ($session->getRows() as $row) {
        $data = $rowsData[$row->getId()] ?? null;
        if (!$data) {
            continue;
        }

        $row->setResolvedName(($data['resolvedName'] ?? '') !== '' ? $data['resolvedName'] : $row->getRawLabel());
        $row->setResolvedBarcode($data['resolvedBarcode'] ?? null);
        $row->setResolvedProductsRefExt($data['resolvedProductsRefExt'] ?? null);

        $buy = isset($data['resolvedBuyPrice']) && $data['resolvedBuyPrice'] !== ''
            ? (float) str_replace(',', '.', (string) $data['resolvedBuyPrice'])
            : $row->getBuyPrice();

        $sell = isset($data['resolvedSellPrice']) && $data['resolvedSellPrice'] !== ''
            ? (float) str_replace(',', '.', (string) $data['resolvedSellPrice'])
            : round($buy * 1.8, 2);

        $row->setResolvedBuyPrice($buy);
        $row->setResolvedSellPrice($sell);

        $row->setResolvedVat(($data['resolvedVat'] ?? '') !== '' ? $data['resolvedVat'] : '20');
        $row->setResolvedAccountingAccount(($data['resolvedAccountingAccount'] ?? '') !== '' ? $data['resolvedAccountingAccount'] : '707002');

        $row->setResolvedCategoryId(isset($data['resolvedCategoryId']) && $data['resolvedCategoryId'] !== '' ? (int) $data['resolvedCategoryId'] : null);
        
        $row->setResolvedSupplierId(isset($data['resolvedSupplierId']) && $data['resolvedSupplierId'] !== '' ? (int) $data['resolvedSupplierId'] : $session->getSupplierId());
        $row->setResolvedBrandId(isset($data['resolvedBrandId']) && $data['resolvedBrandId'] !== '' ? (int) $data['resolvedBrandId'] : null);

        $row->setIsIgnored(!empty($data['isIgnored']));
        $row->setIsReady($row->isReadyToCreate());
    }

    $this->em->flush();

    $this->addFlash('success', 'Grille enregistrée.');

    return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $id]);
}

#[Route('/{id}/import-selected', name: 'import_selected', requirements: ['id' => '\d+'], methods: ['POST'])]
public function importSelected(int $id, Request $request): Response
{
    /** @var MobileImportSession|null $session */
    $session = $this->em->getRepository(MobileImportSession::class)->find($id);
    if (!$session) {
        throw $this->createNotFoundException('Session introuvable');
    }

    if (!$this->isCsrfTokenValid('hib_mobile_save_grid_' . $id, (string) $request->request->get('_csrf_token'))) {
        throw $this->createAccessDeniedException('CSRF invalid');
    }

    if (!$session->getHibInventoryInputId()) {
        $this->addFlash('error', 'Créer d’abord l’arrivage Hiboutik.');
        return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $id]);
    }

    $rowsData = (array) $request->request->all('rows');
    $selectedIds = array_values(array_filter(
        array_map('intval', (array) $request->request->all('selected_ids')),
        static fn (int $v): bool => $v > 0
    ));

    if (!$selectedIds) {
        $this->addFlash('error', 'Aucune ligne cochée.');
        return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $id]);
    }

    // ------------------------------------------------------------
    // 1) On sauvegarde d'abord la grille (comme saveGrid)
    // ------------------------------------------------------------
    foreach ($session->getRows() as $row) {
        $data = $rowsData[$row->getId()] ?? null;
        if (!$data) {
            continue;
        }

        $row->setResolvedName(($data['resolvedName'] ?? '') !== '' ? $data['resolvedName'] : $row->getRawLabel());

        $row->setResolvedBarcode(
            ($data['resolvedBarcode'] ?? '') !== ''
                ? $data['resolvedBarcode']
                : ($row->getImei() ?: ($row->getEan() ?: ($row->getSku() ?: null)))
        );

        $row->setResolvedProductsRefExt(($data['resolvedProductsRefExt'] ?? '') !== '' ? $data['resolvedProductsRefExt'] : $row->getSku());

        $buy = isset($data['resolvedBuyPrice']) && $data['resolvedBuyPrice'] !== ''
            ? (float) str_replace(',', '.', (string) $data['resolvedBuyPrice'])
            : $row->getBuyPrice();

        $sell = isset($data['resolvedSellPrice']) && $data['resolvedSellPrice'] !== ''
            ? (float) str_replace(',', '.', (string) $data['resolvedSellPrice'])
            : round($buy * 1.8, 2);

        $row->setResolvedBuyPrice($buy);
        $row->setResolvedSellPrice($sell);

        $row->setResolvedVat(($data['resolvedVat'] ?? '') !== '' ? $data['resolvedVat'] : '20');
        $row->setResolvedAccountingAccount(($data['resolvedAccountingAccount'] ?? '') !== '' ? $data['resolvedAccountingAccount'] : '707002');

        $row->setResolvedCategoryId(
            isset($data['resolvedCategoryId']) && $data['resolvedCategoryId'] !== ''
                ? (int) $data['resolvedCategoryId']
                : null
        );

        $row->setResolvedSupplierId(
            isset($data['resolvedSupplierId']) && $data['resolvedSupplierId'] !== ''
                ? (int) $data['resolvedSupplierId']
                : $session->getSupplierId()
        );

        if (method_exists($row, 'setResolvedBrandId')) {
            $row->setResolvedBrandId(
                isset($data['resolvedBrandId']) && $data['resolvedBrandId'] !== ''
                    ? (int) $data['resolvedBrandId']
                    : null
            );
        }

        $row->setIsIgnored(!empty($data['isIgnored']));
        $row->setIsReady($row->isReadyToCreate());
    }

    // ------------------------------------------------------------
    // 2) On importe seulement les lignes cochées
    // ------------------------------------------------------------
    $ok = 0;
    $ko = 0;

    foreach ($session->getRows() as $row) {
        if (!in_array((int) $row->getId(), $selectedIds, true)) {
            continue;
        }

        if ($row->getCreatedProductId()) {
            continue;
        }

        if (method_exists($row, 'isIsIgnored') && $row->isIsIgnored()) {
            continue;
        }

        if (method_exists($row, 'isIsBlocked') && $row->isIsBlocked()) {
            $row->setStatus('error');
            $row->setErrorMessage($row->getBlockReason() ?: 'Ligne bloquée.');
            $ko++;
            continue;
        }

        $res = $this->creator->findOrCreateFromRow($row, $session->getHibInventoryInputId());

        if ($res['ok'] ?? false) {
            $row->setCreatedProductId((int) $res['product_id']);
            $row->setStatus('imported');
            $row->setErrorMessage(null);
            $ok++;
        } else {
            $row->setStatus('error');
            $row->setErrorMessage($this->humanizeImportError($res));
            $ko++;
        }
    }

    $this->em->flush();

    $this->addFlash('success', sprintf('Import sélection terminé : %d OK / %d KO', $ok, $ko));

    return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $id]);
}


#[Route('/{id}/reapply-selected', name: 'reapply_selected', requirements: ['id' => '\d+'], methods: ['POST'])]
public function reapplySelected(int $id, Request $request): Response
{
    /** @var MobileImportSession|null $session */
    $session = $this->em->getRepository(MobileImportSession::class)->find($id);
    if (!$session) {
        throw $this->createNotFoundException('Session introuvable');
    }

    if (!$this->isCsrfTokenValid('hib_mobile_save_grid_' . $id, (string) $request->request->get('_csrf_token'))) {
        throw $this->createAccessDeniedException('CSRF invalid');
    }

    $rowsData = (array) $request->request->all('rows');
    $selectedIds = array_values(array_filter(
        array_map('intval', (array) $request->request->all('selected_ids')),
        static fn (int $v): bool => $v > 0
    ));

    if (!$selectedIds) {
        $this->addFlash('error', 'Aucune ligne cochée.');
        return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $id]);
    }

    // ------------------------------------------------------------
    // 1) On resauvegarde d'abord la grille
    // ------------------------------------------------------------
    foreach ($session->getRows() as $row) {
        $data = $rowsData[$row->getId()] ?? null;
        if (!$data) {
            continue;
        }

        $row->setResolvedName(($data['resolvedName'] ?? '') !== '' ? $data['resolvedName'] : $row->getRawLabel());

        $row->setResolvedBarcode(
            ($data['resolvedBarcode'] ?? '') !== ''
                ? $data['resolvedBarcode']
                : ($row->getImei() ?: ($row->getEan() ?: ($row->getSku() ?: null)))
        );

        $row->setResolvedProductsRefExt(
            ($data['resolvedProductsRefExt'] ?? '') !== ''
                ? $data['resolvedProductsRefExt']
                : $row->getSku()
        );

        $buy = isset($data['resolvedBuyPrice']) && $data['resolvedBuyPrice'] !== ''
            ? (float) str_replace(',', '.', (string) $data['resolvedBuyPrice'])
            : $row->getBuyPrice();

        $sell = isset($data['resolvedSellPrice']) && $data['resolvedSellPrice'] !== ''
            ? (float) str_replace(',', '.', (string) $data['resolvedSellPrice'])
            : round($buy * 1.8, 2);

        $row->setResolvedBuyPrice($buy);
        $row->setResolvedSellPrice($sell);

        $row->setResolvedVat(($data['resolvedVat'] ?? '') !== '' ? $data['resolvedVat'] : '20');
        $row->setResolvedAccountingAccount(($data['resolvedAccountingAccount'] ?? '') !== '' ? $data['resolvedAccountingAccount'] : '707002');

        $row->setResolvedCategoryId(
            isset($data['resolvedCategoryId']) && $data['resolvedCategoryId'] !== ''
                ? (int) $data['resolvedCategoryId']
                : null
        );

        $row->setResolvedSupplierId(
            isset($data['resolvedSupplierId']) && $data['resolvedSupplierId'] !== ''
                ? (int) $data['resolvedSupplierId']
                : $session->getSupplierId()
        );

        if (method_exists($row, 'setResolvedBrandId')) {
            $row->setResolvedBrandId(
                isset($data['resolvedBrandId']) && $data['resolvedBrandId'] !== ''
                    ? (int) $data['resolvedBrandId']
                    : null
            );
        }

        $row->setIsIgnored(!empty($data['isIgnored']));
        $row->setIsReady($row->isReadyToCreate());
    }

    // ------------------------------------------------------------
    // 2) Réappliquer sur les produits déjà importés
    // ------------------------------------------------------------
    $ok = 0;
    $ko = 0;

    foreach ($session->getRows() as $row) {
        if (!in_array((int) $row->getId(), $selectedIds, true)) {
            continue;
        }

        if (!$row->getCreatedProductId()) {
            $row->setStatus('error');
            $row->setErrorMessage('Cette ligne n’a pas encore été importée.');
            $ko++;
            continue;
        }

        $res = $this->creator->reapplyResolvedFieldsToExistingProduct($row);

        if ($res['ok'] ?? false) {
            $row->setStatus('imported');
            $row->setErrorMessage(null);
            $ok++;
        } else {
            $row->setStatus('error');
            $row->setErrorMessage($this->humanizeImportError($res));
            $ko++;
        }
    }

    $this->em->flush();

    $this->addFlash('success', sprintf('Réapplication terminée : %d OK / %d KO', $ok, $ko));

    return $this->redirectToRoute('admin_hib_mobile_import_show', ['id' => $id]);
}



}