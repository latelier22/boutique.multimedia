<?php

namespace App\Controller\Admin\Hiboutik;

use App\Entity\Hiboutik\InvoicePointingRow;
use App\Entity\Hiboutik\InvoicePointingSession;
use App\Service\CacheApiClient;
use App\Service\Pdf\UtopyaMultiInvoicePointingParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use App\Service\HiboutikClient;

#[Route('/admin/hiboutik/invoice-pointing', name: 'admin_invoice_pointing_')]
class InvoicePointingController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UtopyaMultiInvoicePointingParser $utopyaParser,
        private CacheApiClient $cacheApi,
        private HiboutikClient $hib,
        
    ) {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $sessions = $this->em->getRepository(InvoicePointingSession::class)
            ->createQueryBuilder('s')
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('@SyliusAdmin/Hiboutik/InvoicePointing/index.html.twig', [
            'sessions' => $sessions,
        ]);
    }

    #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
    public function import(Request $request): Response
    {
        $error = null;
        $preview = [
            'rows' => [],
            'matched_lines' => [],
            'unmatched_candidates' => [],
            'raw_lines' => [],
        ];
        $fileLabel = null;

        if ($request->isMethod('POST')) {
            /** @var UploadedFile|null $uploaded */
            $uploaded = $request->files->get('pdf_file');

            if (!$uploaded) {
                $error = 'Aucun fichier PDF reçu.';
            } elseif (!$uploaded->isValid()) {
                $error = 'Upload invalide. Code erreur : ' . $uploaded->getError();
            } elseif (strtolower((string) $uploaded->getClientOriginalExtension()) !== 'pdf') {
                $error = 'Le fichier envoyé doit être un PDF.';
            } else {
                try {
                    $fileLabel = $uploaded->getClientOriginalName();
                    $preview = $this->utopyaParser->parse($uploaded->getPathname());
                } catch (\Throwable $e) {
                    $error = 'Erreur parser PDF : ' . $e->getMessage();
                }
            }
        }

        return $this->render('@SyliusAdmin/Hiboutik/InvoicePointing/import.html.twig', [
            'file' => $fileLabel,
            'rows' => $preview['rows'] ?? [],
            'matched' => $preview['matched_lines'] ?? [],
            'unmatched' => $preview['unmatched_candidates'] ?? [],
            'rawLines' => $preview['raw_lines'] ?? [],
            'error' => $error,
        ]);
    }

    #[Route('/create-session', name: 'create_session', methods: ['POST'])]
    public function createSession(Request $request): Response
    {
        /** @var UploadedFile|null $uploaded */
        $uploaded = $request->files->get('pdf_file');

        if (!$uploaded) {
            $this->addFlash('error', 'Aucun fichier PDF reçu.');
            return $this->redirectToRoute('admin_invoice_pointing_import');
        }

        if (!$uploaded->isValid()) {
            $this->addFlash('error', 'Upload invalide. Code erreur : ' . $uploaded->getError());
            return $this->redirectToRoute('admin_invoice_pointing_import');
        }

        if (strtolower((string) $uploaded->getClientOriginalExtension()) !== 'pdf') {
            $this->addFlash('error', 'Le fichier envoyé doit être un PDF.');
            return $this->redirectToRoute('admin_invoice_pointing_import');
        }

        $uploadDir = $this->getParameter('kernel.project_dir') . '/var/invoice-pointing';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $storedFilename = uniqid('invoice_pointing_', true) . '.pdf';

        try {
            $uploaded->move($uploadDir, $storedFilename);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Impossible de stocker le fichier : ' . $e->getMessage());
            return $this->redirectToRoute('admin_invoice_pointing_import');
        }

        $filePath = $uploadDir . '/' . $storedFilename;

        try {
            $parsed = $this->utopyaParser->parse($filePath);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur de parsing : ' . $e->getMessage());
            return $this->redirectToRoute('admin_invoice_pointing_import');
        }

        $session = new InvoicePointingSession();
        $session->setSourceFilename($uploaded->getClientOriginalName());
        $session->setStoredFilename($storedFilename);
        $session->setStatus('draft');
        $session->setSupplierName('UTOPYA');
        $session->setMeta([
            'parser' => 'utopya_multi_invoice_pointing',
            'row_count' => count($parsed['rows'] ?? []),
        ]);

       foreach ((array) ($parsed['rows'] ?? []) as $rowData) {
    $imeis = array_values(array_filter((array) ($rowData['imeis'] ?? []), static fn ($v) => trim((string) $v) !== ''));

    // Cas multi-IMEI : on éclate en plusieurs lignes
    if (count($imeis) > 1) {
        foreach ($imeis as $idx => $oneImei) {
            $row = new InvoicePointingRow();

            $row->setLineNumber((int) ($rowData['line_number'] ?? 0));
            $row->setPageNumber(isset($rowData['page_number']) ? (int) $rowData['page_number'] : null);
            $row->setInvoiceNumber($rowData['invoice_number'] ?? null);

            $invoiceDate = null;
if (!empty($rowData['invoice_date'])) {
    $rawInvoiceDate = trim((string) $rowData['invoice_date']);

    $invoiceDate =
        \DateTimeImmutable::createFromFormat('Y-m-d', $rawInvoiceDate)
        ?: \DateTimeImmutable::createFromFormat('d/m/Y', $rawInvoiceDate)
        ?: null;
}
$row->setInvoiceDate($invoiceDate);
            $row->setSku($rowData['sku'] ?? null);
            $row->setEan($rowData['ean'] ?? null);
            $row->setImei((string) $oneImei);
            $row->setImeis([(string) $oneImei]);
            $row->setRawLabel((string) ($rowData['label'] ?? ''));
            $row->setQuantity(1);
            $row->setBuyPrice((float) ($rowData['unit_price'] ?? 0));
            $row->setTotalHt((float) ($rowData['unit_price'] ?? 0));
            $row->setVat($rowData['vat'] ?? null);
            $row->setCurrencyCode('EUR');

            if (method_exists($row, 'setUnitIndex')) {
                $row->setUnitIndex($idx + 1);
            }

            $row->setMatchType('none');
            $row->setMatchedProductId(null);
            $row->setIsPointed(false);
            $row->setPointedAt(null);
            $row->setStatus('draft');
            $row->setErrorMessage(null);

            $rawData = $rowData;
            $rawData['imei'] = (string) $oneImei;
            $rawData['imeis'] = [(string) $oneImei];
            $rawData['quantity'] = 1;
            $rawData['unit_index'] = $idx + 1;
            $row->setRawData($rawData);

            $session->addRow($row);
        }

        continue;
    }

    // Cas simple : 1 ligne = 1 produit
    $row = new InvoicePointingRow();

    $row->setLineNumber((int) ($rowData['line_number'] ?? 0));
    $row->setPageNumber(isset($rowData['page_number']) ? (int) $rowData['page_number'] : null);
    $row->setInvoiceNumber($rowData['invoice_number'] ?? null);

    $invoiceDate = null;
if (!empty($rowData['invoice_date'])) {
    $rawInvoiceDate = trim((string) $rowData['invoice_date']);

    $invoiceDate =
        \DateTimeImmutable::createFromFormat('Y-m-d', $rawInvoiceDate)
        ?: \DateTimeImmutable::createFromFormat('d/m/Y', $rawInvoiceDate)
        ?: null;
}
$row->setInvoiceDate($invoiceDate);

    $row->setSku($rowData['sku'] ?? null);
    $row->setEan($rowData['ean'] ?? null);
    $row->setImei($rowData['imei'] ?? null);
    $row->setImeis((array) ($rowData['imeis'] ?? []));
    $row->setRawLabel((string) ($rowData['label'] ?? ''));
    $row->setQuantity((int) ($rowData['qty'] ?? 1));
    $row->setBuyPrice((float) ($rowData['unit_price'] ?? 0));
    $row->setTotalHt(isset($rowData['total_ht']) ? (float) $rowData['total_ht'] : null);
    $row->setVat($rowData['vat'] ?? null);
    $row->setCurrencyCode('EUR');

    $row->setMatchType('none');
    $row->setMatchedProductId(null);
    $row->setIsPointed(false);
    $row->setPointedAt(null);
    $row->setStatus('draft');
    $row->setErrorMessage(null);
    $row->setRawData($rowData);

    $session->addRow($row);
}

        $this->em->persist($session);
        $this->em->flush();

        $this->addFlash('success', 'Session de pointage créée (#' . $session->getId() . ').');

        return $this->redirectToRoute('admin_invoice_pointing_show', [
            'id' => $session->getId(),
            'imei_filter' => 'with',
        ]);
    }

  #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
public function show(Request $request, InvoicePointingSession $session): Response
{
    $imeiFilter = (string) $request->query->get('imei_filter', 'with');

    $rows = $session->getRows()->toArray();

    if ($imeiFilter === 'with') {
        $rows = array_values(array_filter($rows, static function (InvoicePointingRow $row): bool {
            return $row->hasImei();
        }));
    } elseif ($imeiFilter === 'without') {
        $rows = array_values(array_filter($rows, static function (InvoicePointingRow $row): bool {
            return !$row->hasImei();
        }));
    }

    $matchedProducts = [];

    foreach ($rows as $row) {
        if (!$row instanceof InvoicePointingRow) {
            continue;
        }

        $matchedProductId = $row->getMatchedProductId();
        if (!$matchedProductId || isset($matchedProducts[$matchedProductId])) {
            continue;
        }

        $product = $this->cacheApi->getProductById((int) $matchedProductId);
        if (is_array($product)) {
            $matchedProducts[$matchedProductId] = $product;
        }
    }

    return $this->render('@SyliusAdmin/Hiboutik/InvoicePointing/show.html.twig', [
        'session' => $session,
        'rows' => $rows,
        'imeiFilter' => $imeiFilter,
        'matchedProducts' => $matchedProducts,
    ]);
}

    #[Route('/row/{id}/point', name: 'point_row', requirements: ['id' => '\d+'], methods: ['POST'])]
public function pointRow(Request $request, InvoicePointingRow $row): Response
{
    if (!$this->isCsrfTokenValid('point_invoice_row_' . $row->getId(), (string) $request->request->get('_token'))) {
        throw $this->createAccessDeniedException('CSRF invalide.');
    }

    $matchedProductId = $request->request->get('matched_product_id');
    $matchType = (string) $request->request->get('match_type', 'manual');

    $productId = $matchedProductId !== null && $matchedProductId !== '' ? (int) $matchedProductId : null;

    $row->setMatchedProductId($productId);
    $row->setMatchType($matchType);
    $row->setIsPointed($productId !== null);
    $row->setPointedAt($productId !== null ? new \DateTimeImmutable() : null);

    if ($productId) {
        

        // supplier du pointage : ici UTOPYA = 1
        // adapte si ton id fournisseur UTOPYA est différent
        $supplierId = 1;

      
$newRef = $this->buildProductRefExtFromRow($row);

die;
        $this->hib->updateProductAttributes($productId, [
            'products_ref_ext' => $newRef,
            'product_supplier' => (string) $supplierId,
        ]);
    }

    $this->cacheApi->refreshProduct($productId);
    $this->em->flush();

    $this->addFlash('success', 'Ligne mise à jour et produit Hiboutik modifié.');

    $imeiFilter = (string) $request->request->get('imei_filter', 'with');

    $params = [
        'id' => $row->getSession()->getId(),
    ];

    if (in_array($imeiFilter, ['with', 'without', 'all'], true)) {
        $params['imei_filter'] = $imeiFilter;
    }

    return $this->redirectToRoute('admin_invoice_pointing_show', $params);
}

    #[Route('/row/{id}/unpoint', name: 'unpoint_row', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unpointRow(Request $request, InvoicePointingRow $row): Response
    {
        if (!$this->isCsrfTokenValid('unpoint_invoice_row_' . $row->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        $row->setMatchedProductId(null);
        $row->setMatchType('none');
        $row->setIsPointed(false);
        $row->setPointedAt(null);

        $this->em->flush();

        $this->addFlash('success', 'Pointage supprimé.');

        $imeiFilter = (string) $request->request->get('imei_filter', 'with');

        $params = [
            'id' => $row->getSession()->getId(),
        ];

        if (in_array($imeiFilter, ['with', 'without', 'all'], true)) {
            $params['imei_filter'] = $imeiFilter;
        }

        return $this->redirectToRoute('admin_invoice_pointing_show', $params);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, InvoicePointingSession $session): Response
    {
        if (!$this->isCsrfTokenValid('delete_invoice_pointing_session_' . $session->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        $this->em->remove($session);
        $this->em->flush();

        $this->addFlash('success', 'Session supprimée.');

        return $this->redirectToRoute('admin_invoice_pointing_index');
    }

  #[Route('/search-product', name: 'search_product', methods: ['GET'])]
public function searchProduct(Request $request): JsonResponse
{
    $q = trim((string) $request->query->get('q', ''));

    if ($q === '') {
        return $this->json([
            'ok' => true,
            'count' => 0,
            'items' => [],
        ]);
    }

    $res = $this->cacheApi->searchAdminProducts([
        'q' => $q,
        'include_archived' => 1,
        'include_hidden' => 1,
        'limit' => 50,
    ]);

    return $this->json([
        'ok' => (bool) ($res['ok'] ?? false),
        'count' => (int) ($res['count'] ?? 0),
        'total' => (int) ($res['total'] ?? 0),
        'items' => array_map(static function (array $row): array {
            $lastPurchase = is_array($row['last_purchase_history'] ?? null)
                ? $row['last_purchase_history']
                : null;

            return [
                'product_id' => (int) ($row['product_id'] ?? 0),
                'name' => (string) ($row['product_model'] ?? $row['name'] ?? ''),
                'barcode' => (string) ($row['product_barcode'] ?? $row['barcode'] ?? ''),
                'sku' => (string) ($row['products_ref_ext'] ?? $row['sku'] ?? ''),
                'product_arch' => (bool) ($row['product_arch'] ?? false),
                'stock_available' => $row['stock_available'] ?? 0,
                'sell_price' => $row['product_price'] ?? $row['price'] ?? null,
                'discount_price' => $row['product_discount_price'] ?? $row['discount_price'] ?? null,
                'supplier_name' => $row['supplier_name'] ?? null,
                'product_supplier' => $row['product_supplier'] ?? null,
                'last_purchase_history' => $lastPurchase,
                'sale_info' => [
                    'found' => false,
                    'label' => 'inconnu',
                ],
                'raw' => $row,
            ];
        }, (array) ($res['data'] ?? [])),
        'meta' => $res['meta'] ?? [],
    ]);
}

#[Route('/row/{id}/archive-product', name: 'archive_product', requirements: ['id' => '\d+'], methods: ['POST'])]
public function archiveProduct(Request $request, InvoicePointingRow $row): Response
{
    if (!$this->isCsrfTokenValid('archive_invoice_row_' . $row->getId(), (string) $request->request->get('_token'))) {
        throw $this->createAccessDeniedException('CSRF invalide.');
    }

    $productId = $row->getMatchedProductId();

    if (!$productId) {
        $this->addFlash('error', 'Aucun produit associé à cette ligne.');
    } else {
        $res = $this->hib->updateProductAttributes((int) $productId, [
            'product_arch' => '1',
        ]);

        if ($res['ok'] ?? false) {
            $this->cacheApi->refreshProduct($productId);
            $this->addFlash('success', 'Produit archivé dans Hiboutik.');
        } else {
            $this->addFlash('error', 'Impossible d’archiver le produit Hiboutik.');
        }
    }

    $imeiFilter = (string) $request->request->get('imei_filter', 'with');

    $params = [
        'id' => $row->getSession()->getId(),
    ];

    if (in_array($imeiFilter, ['with', 'without', 'all'], true)) {
        $params['imei_filter'] = $imeiFilter;
    }

    return $this->redirectToRoute('admin_invoice_pointing_show', $params);
}

    #[Route('/{id}/auto-point-imei', name: 'auto_point_imei', requirements: ['id' => '\d+'], methods: ['POST'])]
public function autoPointImei(Request $request, InvoicePointingSession $session): Response
{
    if (!$this->isCsrfTokenValid('auto_point_imei_' . $session->getId(), (string) $request->request->get('_token'))) {
        throw $this->createAccessDeniedException('CSRF invalide.');
    }

    $rows = $session->getRows()->toArray();

    $matched = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        if (!$row instanceof InvoicePointingRow) {
            continue;
        }

        if (!$row->hasImei()) {
            $skipped++;
            continue;
        }

        if ($row->getMatchedProductId()) {
            $skipped++;
            continue;
        }

        $imei = trim((string) $row->getImei());
        if ($imei === '') {
            $skipped++;
            continue;
        }

        $res = $this->cacheApi->searchAdminProducts([
            'q' => $imei,
            'include_archived' => 1,
            'include_hidden' => 1,
            'limit' => 10,
        ]);

        $items = (array) ($res['data'] ?? []);

        $exactMatches = array_values(array_filter($items, static function (array $item) use ($imei): bool {
            $barcode = trim((string) ($item['product_barcode'] ?? $item['barcode'] ?? ''));
            return $barcode !== '' && $barcode === $imei;
        }));

      if (count($exactMatches) === 1) {
    $product = $exactMatches[0];
    $productId = (int) ($product['product_id'] ?? 0);

    $row->setMatchedProductId($productId);
    $row->setMatchType('imei_auto');
    $row->setIsPointed(true);
    $row->setPointedAt(new \DateTimeImmutable());

    if ($productId > 0) {
        $supplierId = 1; // UTOPYA
        $newRef = $this->buildProductRefExtFromRow($row);
        $supplierReference = trim((string) ($row->getSku() ?? ''));

        $fields = [
            'products_ref_ext' => $newRef,
            'product_supplier' => (string) $supplierId,
        ];

        if ($supplierReference !== '') {
            $fields['product_supplier_reference'] = $supplierReference;
        }

        $this->hib->updateProductAttributes($productId, $fields);
        $this->cacheApi->refreshProduct($productId);
    }

    $matched++;
} else {
    $skipped++;
}
    }

    $this->em->flush();

    $this->addFlash('success', sprintf(
        'Auto-association IMEI terminée : %d ligne(s) associée(s), %d ignorée(s).',
        $matched,
        $skipped
    ));

    $imeiFilter = (string) $request->request->get('imei_filter', 'with');

    $params = ['id' => $session->getId()];
    if (in_array($imeiFilter, ['with', 'without', 'all'], true)) {
        $params['imei_filter'] = $imeiFilter;
    }

    return $this->redirectToRoute('admin_invoice_pointing_show', $params);
}
#[Route('/{id}/unpoint-all', name: 'unpoint_all', requirements: ['id' => '\d+'], methods: ['POST'])]
public function unpointAll(Request $request, InvoicePointingSession $session): Response
{
    if (!$this->isCsrfTokenValid('unpoint_all_invoice_pointing_' . $session->getId(), (string) $request->request->get('_token'))) {
        throw $this->createAccessDeniedException('CSRF invalide.');
    }

    foreach ($session->getRows() as $row) {
        if (!$row instanceof InvoicePointingRow) {
            continue;
        }

        $row->setMatchedProductId(null);
        $row->setMatchType('none');
        $row->setIsPointed(false);
        $row->setPointedAt(null);
    }

    $this->em->flush();

    $this->addFlash('success', 'Toutes les associations de la session ont été supprimées.');

    $imeiFilter = (string) $request->request->get('imei_filter', 'with');

    $params = [
        'id' => $session->getId(),
    ];

    if (in_array($imeiFilter, ['with', 'without', 'all'], true)) {
        $params['imei_filter'] = $imeiFilter;
    }

    return $this->redirectToRoute('admin_invoice_pointing_show', $params);
}
private function buildProductRefExtFromRow(InvoicePointingRow $row): string
{
    $invoice = preg_replace('/\s+/', '', trim((string) ($row->getInvoiceNumber() ?? ''))) ?? '';
    $date = $row->getInvoiceDate() instanceof \DateTimeInterface
        ? $row->getInvoiceDate()->format('ymd')
        : '';

    $value = implode('-', array_values(array_filter([
        $invoice,
        $date,
    ], static fn ($v) => $v !== '')));

    return mb_substr($value, 0, 20);
}
}