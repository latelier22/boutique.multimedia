<?php

namespace App\Controller\Admin\Hiboutik;

use App\Entity\Hiboutik\IncomingSupplierAttachment;
use App\Entity\Hiboutik\IncomingSupplierDocument;
use App\Entity\Hiboutik\MobileImportRow;
use App\Entity\Hiboutik\MobileImportSession;
use App\Service\Hiboutik\ArrivageMailFetcher;
use App\Service\Hiboutik\MobileImportParser;
use App\Service\Pdf\UtopyaInvoiceParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use App\Service\Pdf\JensMobilePdfParser;
use App\Service\Hiboutik\ImportParserRegistry;

#[Route('/admin/arrivages/documents', name: 'admin_arrivages_documents_')]
class IncomingDocumentController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private MobileImportParser $parser,
        private UtopyaInvoiceParser $utopyaInvoiceParser,
        private JensMobilePdfParser $jensMobilePdfParser,
        private ImportParserRegistry $importParserRegistry,
    ) {
    }

    #[Route('/', name: 'index')]
    public function index(): Response
    {
        $docs = $this->em->getRepository(IncomingSupplierDocument::class)
            ->createQueryBuilder('d')
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('@SyliusAdmin/Hiboutik/Arrivages/documents/index.html.twig', [
            'documents' => $docs,
        ]);
    }

    #[Route('/attachment/{filename}', name: 'attachment')]
    public function serveAttachment(string $filename): Response
    {
        $safeFilename = basename($filename);
        $path = $this->getParameter('kernel.project_dir') . '/var/arrivage-mails/' . $safeFilename;

        if (!is_file($path)) {
            throw $this->createNotFoundException('Fichier introuvable');
        }

        return $this->file($path, $safeFilename);
    }

  #[Route('/{id}/prepare', name: 'prepare', requirements: ['id' => '\d+'], methods: ['POST'])]
public function prepare(IncomingSupplierDocument $document): Response
{
    $existingSessionId = $document->getImportSessionId();
    if ($existingSessionId) {
        $existingSession = $this->em->getRepository(MobileImportSession::class)->find($existingSessionId);
        if ($existingSession) {
            $this->addFlash('info', 'Une session de préparation existe déjà pour ce document (#' . $existingSession->getId() . ').');

            return $this->redirectToRoute('admin_hib_mobile_import_show', [
                'id' => $existingSession->getId(),
            ]);
        }

        $document->setImportSessionId(null);
    }

    $attachment = null;
    $pdfPreferred = null;
    $spreadsheetFallback = null;
    $pdfFallback = null;

    foreach ($document->getAttachments() as $att) {
        $ext = strtolower((string) pathinfo((string) $att->getOriginalFilename(), PATHINFO_EXTENSION));

        // PDF fournisseur connu = priorité absolue
        if (
            $ext === 'pdf'
            && (
                $this->shouldUseUtopyaPdfParser($document, $att)
                || $this->shouldUseJensMobilePdfParser($document, $att)
            )
        ) {
            $pdfPreferred = $att;
            break;
        }

        // Tableur = fallback
        if (in_array($ext, ['csv', 'xlsx', 'xls'], true) && $spreadsheetFallback === null) {
            $spreadsheetFallback = $att;
        }

        // Autre PDF = secours
        if ($ext === 'pdf' && $pdfFallback === null) {
            $pdfFallback = $att;
        }
    }

    $attachment = $pdfPreferred ?? $spreadsheetFallback ?? $pdfFallback;

    if (!$attachment) {
        $this->addFlash('error', 'Aucune pièce jointe exploitable.');
        return $this->redirectToRoute('admin_arrivages_documents_show', ['id' => $document->getId()]);
    }

    $filePath = $this->getParameter('kernel.project_dir') . '/var/arrivage-mails/' . $attachment->getStoredFilename();

    if (!is_file($filePath)) {
        $this->addFlash('error', 'Le fichier joint est introuvable sur le disque.');
        return $this->redirectToRoute('admin_arrivages_documents_show', ['id' => $document->getId()]);
    }

    $ext = strtolower((string) pathinfo($attachment->getOriginalFilename(), PATHINFO_EXTENSION));

    try {
        // ------------------------------------------------------------
        // PDF UTOPYA
        // ------------------------------------------------------------
        if ($ext === 'pdf' && $this->shouldUseUtopyaPdfParser($document, $attachment)) {
            $pdfData = $this->utopyaInvoiceParser->parse($filePath);

            $parsed = [
                'source_type' => 'invoice',
                'document_code' => $document->getDocumentNumber() ?: ($pdfData['document_code'] ?? null),
                'document_date' => $pdfData['document_date'] ?? null,
                'meta' => [
                    'parser' => 'utopya_pdf',
                    'row_count' => count($pdfData['rows'] ?? []),
                ],
                'rows' => array_map(function (array $r, int $idx): array {
                    $meta = $this->parsePdfLabelMeta((string) ($r['label'] ?? ''));

                    return [
                        'line_number' => $idx + 1,
                        'unit_index' => 1,
                        'sku' => $r['sku'] ?? null,
                        'ean' => $r['ean'] ?? null,
                        'raw_label' => (string) ($r['label'] ?? ''),
                        'imei' => $r['imei'] ?? null,
                        'quantity' => (int) ($r['qty'] ?? 1),
                        'buy_price' => (float) ($r['unit_price'] ?? 0),
                        'currency_code' => 'EUR',
                        'parsed_brand' => $meta['brand'],
                        'parsed_model' => $meta['model'],
                        'parsed_storage' => $meta['storage'],
                        'parsed_color' => $meta['color'],
                        'parsed_grade' => $meta['grade'],
                        'requires_imei' => false,
                        'status' => 'draft',
                        'raw_data' => $r,
                    ];
                }, $pdfData['rows'] ?? [], array_keys($pdfData['rows'] ?? [])),
            ];

            if (($parsed['source_type'] ?? null) === 'invoice' && $document->getDocumentType() === null) {
                $document->setDocumentType('FA');
            }

        // ------------------------------------------------------------
        // PDF JENS MOBILE
        // ------------------------------------------------------------
        } elseif ($ext === 'pdf' && $this->shouldUseJensMobilePdfParser($document, $attachment)) {
            $pdfData = $this->jensMobilePdfParser->parse($filePath);

            $parsed = [
                'source_type' => (string) ($pdfData['source_type'] ?? 'purchase_order'),
                'document_code' => $document->getDocumentNumber() ?: ($pdfData['document_code'] ?? null),
                'document_date' => $pdfData['document_date'] ?? null,
                'meta' => [
                    'parser' => 'jens_mobile_pdf',
                    'row_count' => count($pdfData['rows'] ?? []),
                ],
                'rows' => array_map(function (array $r, int $idx): array {
                    $meta = $this->parsePdfLabelMeta((string) ($r['label'] ?? ''));

                    return [
                        'line_number' => (int) ($r['line_number'] ?? ($idx + 1)),
                        'unit_index' => 1,
                        'sku' => $r['sku'] ?? null,
                        'ean' => $r['ean'] ?? null,
                        'raw_label' => (string) ($r['label'] ?? ''),
                        'imei' => $r['imei'] ?? null,
                        'quantity' => (int) ($r['qty'] ?? 1),
                        'buy_price' => (float) ($r['unit_price'] ?? 0),
                        'currency_code' => 'EUR',
                        'parsed_brand' => $meta['brand'],
                        'parsed_model' => $meta['model'],
                        'parsed_storage' => $meta['storage'],
                        'parsed_color' => $meta['color'],
                        'parsed_grade' => $meta['grade'],
                        'requires_imei' => false,
                        'status' => 'draft',
                        'raw_data' => $r,
                    ];
                }, $pdfData['rows'] ?? [], array_keys($pdfData['rows'] ?? [])),
            ];

            // Jens = commande => BC si pas déjà défini
            if (($parsed['source_type'] ?? null) === 'purchase_order' && $document->getDocumentType() === null) {
                $document->setDocumentType('BC');
            }

            if ($document->getSupplierName() === null) {
                $document->setSupplierName("JENS MOBILE");
            }

        // ------------------------------------------------------------
        // Fallback tableur / parser générique
        // ------------------------------------------------------------
        } else {
            $parsed = $this->parser->parse($filePath);
        }
    } catch (\Throwable $e) {
        $this->addFlash('error', 'Erreur de parsing : ' . $e->getMessage());
        return $this->redirectToRoute('admin_arrivages_documents_show', ['id' => $document->getId()]);
    }

    $session = new MobileImportSession();
    $session->setSourceType((string) ($parsed['source_type'] ?? 'email'));
    $session->setSourceFilename($attachment->getOriginalFilename());
    $session->setStoredFilename($attachment->getStoredFilename());
    $session->setDocumentCode(
        $document->getDocumentNumber()
        ?: $document->getDetectedDocumentCode()
        ?: ($parsed['document_code'] ?? null)
    );

    $parsedDate = null;
    if (!empty($parsed['document_date'])) {
        try {
            $parsedDate = new \DateTimeImmutable((string) $parsed['document_date']);
        } catch (\Throwable) {
            $parsedDate = null;
        }
    }

    $session->setDocumentDate(
        $document->getDocumentDate()
        ?: $document->getDetectedDocumentDate()
        ?: $parsedDate
    );

    $session->setSupplierId($document->getDetectedSupplierId());
    $session->setMeta((array) ($parsed['meta'] ?? []));
    $session->setStatus('draft');

   foreach ((array) ($parsed['rows'] ?? []) as $rowData) {
    $row = new MobileImportRow();

    // -----------------------------
    // Données brutes issues du parser
    // -----------------------------
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
    $this->initializeResolvedDefaults($row, $session);

    $session->addRow($row);
}

    $this->em->persist($session);
    $this->em->flush();

    $document->setImportSessionId($session->getId());
    $document->setProcessingStatus('prepared');
    $this->em->flush();

    $this->addFlash('success', 'Session de préparation créée (#' . $session->getId() . ').');

    return $this->redirectToRoute('admin_hib_mobile_import_prepa', [
        'id' => $session->getId(),
    ]);
}

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(IncomingSupplierDocument $document): Response
    {
        return $this->render('@SyliusAdmin/Hiboutik/Arrivages/documents/show.html.twig', [
            'document' => $document,
        ]);
    }


#[Route('/latest-pdf-prepare', name: 'latest_pdf_prepare', methods: ['POST'])]
public function latestPdfPrepare(Request $request): Response
{
    if (!$this->isCsrfTokenValid('latest_arrivage_pdf_prepare', (string) $request->request->get('_csrf_token'))) {
        throw $this->createAccessDeniedException('CSRF invalid');
    }

    $documents = $this->em->getRepository(IncomingSupplierDocument::class)
        ->createQueryBuilder('d')
        ->leftJoin('d.attachments', 'a')
        ->addSelect('a')
        ->orderBy('d.createdAt', 'DESC')
        ->getQuery()
        ->getResult();

    foreach ($documents as $document) {
        foreach ($document->getAttachments() as $attachment) {
            $ext = strtolower((string) pathinfo((string) $attachment->getOriginalFilename(), PATHINFO_EXTENSION));

            if ($ext === 'pdf') {
                return $this->redirectToRoute('admin_arrivages_documents_prepare_proxy', [
                    'id' => $document->getId(),
                ]);
            }
        }
    }

    $this->addFlash('error', 'Aucun document PDF trouvé.');
    return $this->redirectToRoute('admin_arrivages_documents_index');
}


#[Route('/latest-pdf', name: 'latest_pdf', methods: ['POST'])]
public function latestPdf(Request $request): Response
{
    if (!$this->isCsrfTokenValid('latest_arrivage_pdf', (string) $request->request->get('_csrf_token'))) {
        throw $this->createAccessDeniedException('CSRF invalid');
    }

    $documents = $this->em->getRepository(IncomingSupplierDocument::class)
        ->createQueryBuilder('d')
        ->leftJoin('d.attachments', 'a')
        ->addSelect('a')
        ->orderBy('d.createdAt', 'DESC')
        ->getQuery()
        ->getResult();

    foreach ($documents as $document) {
        foreach ($document->getAttachments() as $attachment) {
            $ext = strtolower((string) pathinfo((string) $attachment->getOriginalFilename(), PATHINFO_EXTENSION));

            if ($ext === 'pdf') {
                return $this->redirectToRoute('admin_arrivages_documents_show', [
                    'id' => $document->getId(),
                ]);
            }
        }
    }

    $this->addFlash('error', 'Aucun document PDF trouvé.');
    return $this->redirectToRoute('admin_arrivages_documents_index');
}



    #[Route('/test-pdf', name: 'test_pdf', methods: ['GET', 'POST'])]
public function testPdf(Request $request): Response
{
    $file = '/var/www/boutique.multimedia/public/test2.pdf';
    $error = null;

    if ($request->isMethod('POST')) {
        /** @var UploadedFile|null $uploaded */
        $uploaded = $request->files->get('pdf_file');

        if (!$uploaded) {
            $error = 'Aucun fichier envoyé.';
        } elseif (strtolower((string) $uploaded->getClientOriginalExtension()) !== 'pdf') {
            $error = 'Le fichier doit être un PDF.';
        } else {
            $file = $uploaded->getPathname();
        }
    }

    $data = [
        'rows' => [],
        'matched_lines' => [],
        'unmatched_candidates' => [],
        'raw_lines' => [],
    ];

    if ($error === null) {
        try {
            $data = $this->utopyaInvoiceParser->parse($file);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }

    return $this->render('@SyliusAdmin/Hiboutik/Arrivages/documents/test_pdf.html.twig', [
        'file' => $file,
        'rows' => $data['rows'] ?? [],
        'matched' => $data['matched_lines'] ?? [],
        'unmatched' => $data['unmatched_candidates'] ?? [],
        'rawLines' => $data['raw_lines'] ?? [],
        'error' => $error,
    ]);
}

#[Route('/import-pdf', name: 'import_pdf', methods: ['GET', 'POST'])]
public function importPdf(Request $request): Response
{
    $fileLabel = null;
    $error = null;

    $data = [
        'rows' => [],
        'matched_lines' => [],
        'unmatched_candidates' => [],
        'raw_lines' => [],
    ];

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
                $data = $this->jensMobilePdfParser->parse($uploaded->getPathname());
            } catch (\Throwable $e) {
                $error = 'Erreur parser PDF : ' . $e->getMessage();
            }
        }
    }

    return $this->render('@SyliusAdmin/Hiboutik/Arrivages/documents/import_pdf.html.twig', [
        'file' => $fileLabel,
        'rows' => $data['rows'] ?? [],
        'matched' => $data['matched_lines'] ?? [],
        'unmatched' => $data['unmatched_candidates'] ?? [],
        'rawLines' => $data['raw_lines'] ?? [],
        'error' => $error,
    ]);
}


#[Route('/import-pdf/create-session', name: 'import_pdf_to_session', methods: ['POST'])]
public function importPdfToSession(Request $request): Response
{
    /** @var UploadedFile|null $uploaded */
    $uploaded = $request->files->get('pdf_file');
    $parserKey = (string) $request->request->get('parser_key', '');

    if (!$uploaded) {
        $this->addFlash('error', 'Aucun fichier PDF reçu.');
        return $this->redirectToRoute('admin_arrivages_documents_import_pdf');
    }

    if (!$uploaded->isValid()) {
        $this->addFlash('error', 'Upload invalide. Code erreur : ' . $uploaded->getError());
        return $this->redirectToRoute('admin_arrivages_documents_import_pdf');
    }

    if ($parserKey === '') {
        $this->addFlash('error', 'Choisir un fournisseur / parser.');
        return $this->redirectToRoute('admin_arrivages_documents_import_pdf');
    }

    $uploadDir = $this->getParameter('kernel.project_dir') . '/var/mobile-imports';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }

    $storedFilename = uniqid('import_', true) . '.' . ($uploaded->getClientOriginalExtension() ?: 'pdf');

    try {
        $uploaded->move($uploadDir, $storedFilename);
    } catch (\Throwable $e) {
        $this->addFlash('error', 'Impossible de stocker le fichier : ' . $e->getMessage());
        return $this->redirectToRoute('admin_arrivages_documents_import_pdf');
    }

    $filePath = $uploadDir . '/' . $storedFilename;

    try {
        $parsed = $this->importParserRegistry->parse($parserKey, $filePath);
    } catch (\Throwable $e) {
        $this->addFlash('error', 'Erreur de parsing : ' . $e->getMessage());
        return $this->redirectToRoute('admin_arrivages_documents_import_pdf');
    }

    $session = new MobileImportSession();
    $session->setSourceType((string) ($parsed['source_type'] ?? 'email'));
    $session->setSourceFilename($uploaded->getClientOriginalName());
    $session->setStoredFilename($storedFilename);
    $session->setDocumentCode($parsed['document_code'] ?? null);

    $parsedDate = null;
    if (!empty($parsed['document_date'])) {
        try {
            $parsedDate = new \DateTimeImmutable((string) $parsed['document_date']);
        } catch (\Throwable) {
            $parsedDate = null;
        }
    }

    $session->setDocumentDate($parsedDate);
    $session->setMeta([
        ...((array) ($parsed['meta'] ?? [])),
        'parser_key' => $parserKey,
    ]);
    $session->setStatus('draft');

  foreach ((array) ($parsed['rows'] ?? []) as $rowData) {
    $row = new MobileImportRow();

    // -----------------------------
    // Données brutes issues du parser
    // -----------------------------
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
    $this->initializeResolvedDefaults($row, $session);

    $session->addRow($row);
}

    $this->em->persist($session);
    $this->em->flush();

    $this->addFlash('success', 'Session créée (#' . $session->getId() . ').');

    return $this->redirectToRoute('admin_hib_mobile_import_show', [
        'id' => $session->getId(),
    ]);
}


private function initializeResolvedDefaults(MobileImportRow $row, MobileImportSession $session): void
{
    $row->setResolvedName($row->getRawLabel() !== '' ? $row->getRawLabel() : null);
    $row->setResolvedBarcode($row->getImei() ?: ($row->getEan() ?: null));
    $row->setResolvedProductsRefExt($row->getSku());
    $row->setResolvedBuyPrice($row->getBuyPrice());
    $row->setResolvedSellPrice(round($row->getBuyPrice() * 1.8, 2));
    $row->setResolvedVat('20');
    $row->setResolvedAccountingAccount('707002');
    $row->setResolvedSupplierId($session->getSupplierId());
    $row->setResolvedCategoryId(null);
    $row->setResolvedCategoryLabel(null);
    $row->setResolvedBrandId(null);
    $row->setResolvedBrandLabel(null);
    $row->setMatchType('none');
    $row->setMatchedProductId(null);
    $row->setIsIgnored(false);
    $row->setIsBlocked(false);
    $row->setBlockReason(null);
    $row->setIsReady($row->isReadyToCreate());
}


private function extractDocumentCodeFromRawLines(array $lines): ?string
{
    foreach ($lines as $line) {
        $line = (string) $line;

        if (preg_match('/Facture\s*#\s*([A-Z0-9-]+)/i', $line, $m)) {
            return trim((string) $m[1]);
        }

        if (preg_match('/Commande\s*#\s*([A-Z0-9-]+)/i', $line, $m)) {
            return trim((string) $m[1]);
        }
    }

    return null;
}

    #[Route('/fetch-mails', name: 'fetch_mails', methods: ['POST'])]
    public function fetchMails(Request $request, ArrivageMailFetcher $fetcher): Response
    {
        if (!$this->isCsrfTokenValid('fetch_arrivage_mails', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRF invalid');
        }

        $result = $fetcher->fetch();

        if (!($result['ok'] ?? false)) {
            $this->addFlash('error', 'Le relevé du courrier a échoué.');
            return $this->redirectToRoute('admin_arrivages_documents_index');
        }

        $this->addFlash('success', sprintf(
            'Relevé terminé : %d mail(s), %d document(s) créés, %d complétés, %d pièce(s) jointes.',
            $result['mail_count'] ?? 0,
            $result['created_documents'] ?? 0,
            $result['completed_documents'] ?? 0,
            $result['saved_attachments'] ?? 0
        ));

        return $this->redirectToRoute('admin_arrivages_documents_index');
    }

    private function shouldUseUtopyaPdfParser(IncomingSupplierDocument $document, IncomingSupplierAttachment $attachment): bool
    {
        $ext = strtolower((string) pathinfo($attachment->getOriginalFilename(), PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            return false;
        }

        $subject = mb_strtoupper((string) ($document->getSubject() ?? ''), 'UTF-8');
        $supplier = mb_strtoupper((string) ($document->getSupplierName() ?? ''), 'UTF-8');
        $filename = mb_strtoupper((string) ($attachment->getOriginalFilename() ?? ''), 'UTF-8');

        if (str_contains($supplier, 'UTOPYA')) {
            return true;
        }

        if (str_contains($subject, 'UTOPYA')) {
            return true;
        }

        if (str_contains($filename, 'UTOPYA')) {
            return true;
        }

        return false;
    }

    private function parsePdfLabelMeta(string $label): array
    {
        $raw = trim($label);

        $brand = null;
        $model = null;
        $storage = null;
        $color = null;
        $grade = null;

        $knownBrands = [
            'APPLE', 'IPHONE', 'SAMSUNG', 'XIAOMI', 'REDMI', 'POCO', 'OPPO',
            'REALME', 'HONOR', 'HUAWEI', 'NOKIA', 'MOTOROLA', 'GOOGLE',
            'ONEPLUS', 'VIVO', 'WIKO',
        ];

        $upper = mb_strtoupper($raw, 'UTF-8');

        foreach ($knownBrands as $b) {
            if (str_contains($upper, $b)) {
                $brand = $b === 'IPHONE' ? 'Apple' : ucfirst(mb_strtolower($b, 'UTF-8'));
                break;
            }
        }

        if (preg_match('/\b(\d+\s?(?:GO|GB|TB))\b/ui', $raw, $m)) {
            $storage = strtoupper(str_replace(' ', '', $m[1]));
        }

        if (preg_match('/\b(NOIR|BLACK|BLANC|WHITE|BLEU|BLUE|VERT|GREEN|ROSE|PINK|VIOLET|PURPLE|GRIS|GREY|GRAY|ARGENT|SILVER|OR|GOLD|TITANE|TITANIUM)\b/ui', $raw, $m)) {
            $color = ucfirst(mb_strtolower($m[1], 'UTF-8'));
        }

        if (preg_match('/\b(GRADE\s*[A-Z0-9]+|A\+|A\/B|A|B|C)\b/ui', $raw, $m)) {
            $grade = strtoupper(trim($m[1]));
        }

        $model = $raw;
        if ($brand !== null) {
            $model = trim(preg_replace('/^' . preg_quote($brand, '/') . '\s*/iu', '', $model) ?? $model);
        }

        return [
            'brand' => $brand,
            'model' => $model !== '' ? $model : null,
            'storage' => $storage,
            'color' => $color,
            'grade' => $grade,
        ];
    }



    private function shouldUseJensMobilePdfParser(IncomingSupplierDocument $document, IncomingSupplierAttachment $attachment): bool
{
    $ext = strtolower((string) pathinfo($attachment->getOriginalFilename(), PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return false;
    }

    $subject = mb_strtoupper((string) ($document->getSubject() ?? ''), 'UTF-8');
    $supplier = mb_strtoupper((string) ($document->getSupplierName() ?? ''), 'UTF-8');
    $filename = mb_strtoupper((string) ($attachment->getOriginalFilename() ?? ''), 'UTF-8');
    $fromEmail = mb_strtoupper((string) ($document->getFromEmail() ?? ''), 'UTF-8');

    if (str_contains($supplier, 'JENS')) {
        return true;
    }

    if (str_contains($subject, 'JENS') || str_contains($subject, 'JENS MOBILE') || str_contains($subject, 'JENSMOBILES')) {
        return true;
    }

    if (str_contains($filename, 'JENS')) {
        return true;
    }

    if (str_contains($fromEmail, 'JENSMOBILES')) {
        return true;
    }

    return false;
}
}