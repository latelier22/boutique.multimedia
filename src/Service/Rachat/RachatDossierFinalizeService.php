<?php

namespace App\Service\Rachat;

use App\Entity\Rachat\RachatDossier;
use App\Entity\Rachat\RachatItem;
use App\Service\HiboutikClient;
use Doctrine\ORM\EntityManagerInterface;

final class RachatDossierFinalizeService
{
    public function __construct(
        private EntityManagerInterface $em,
        private HiboutikClient $hiboutikClient,
        private RachatDossierManager $manager,
        private RachatDossierSignatureManager $signatureManager,
        private RachatDossierPdfGenerator $pdfGenerator,
    ) {
    }

    public function finalizeFromTabletSignature(RachatDossier $dossier, string $dataUrl, bool $acceptedConditions = true): array
    {
        if ($dossier->isLocked()) {
            throw new \RuntimeException('Ce dossier est déjà verrouillé/signé.');
        }

        if (!$acceptedConditions) {
            throw new \RuntimeException('Les conditions doivent être acceptées.');
        }

        if (trim($dataUrl) === '') {
            throw new \RuntimeException('Signature manquante.');
        }

        if ($dossier->getItems()->count() === 0) {
            throw new \RuntimeException('Le dossier doit contenir au moins un item.');
        }

        if (!$dossier->getHibCustomerId()) {
            throw new \RuntimeException('Un client Hiboutik doit être lié avant la signature.');
        }

        $this->manager->prepareForSave($dossier);

        if (!$dossier->getReference()) {
            $this->em->persist($dossier);
            $this->em->flush();
            $this->manager->generateReferenceIfNeeded($dossier);
        }

        $signatureUrl = $this->signatureManager->storeSignatureDataUrl($dossier, $dataUrl);

        $this->paySeller($dossier);

        $storeMeta = $this->hiboutikClient->getDefaultStoreMeta();
        $stockId = (int) ($storeMeta['stock_id'] ?? $storeMeta['store_id'] ?? 1);

        // À adapter si tu veux un autre fournisseur Hiboutik par défaut
        $supplierId = 3;

        $inputId = (int) $this->hiboutikClient->getOrCreateMonthlyRachatInput(
            $stockId,
            $supplierId,
            new \DateTimeImmutable('today')
        );

        foreach ($dossier->getItems() as $item) {
            if (!$this->shouldConvertItemToHib($item)) {
                continue;
            }

            $productId = $this->ensureHiboutikProductForItem($item, $supplierId);

            if ($productId <= 0) {
                throw new \RuntimeException(sprintf(
                    'Impossible de créer le produit Hiboutik pour l’item #%d.',
                    (int) $item->getId()
                ));
            }

            $item->setHibProductId($productId);

            $unitPrice = $this->normalizeAmount($item->getPrixAchat());

            $this->hiboutikClient->addProductToInventoryInput(
                $inputId,
                $productId,
                1,
                $unitPrice
            );
        }

        $this->manager->markSigned($dossier);

        $shop = [
            'name' => 'Multimédia Services & Cash',
            'address' => 'À compléter',
            'zip' => '00000',
            'city' => 'À compléter',
            'country' => 'France',
            'phone' => 'À compléter',
            'email' => 'À compléter',
            'site' => 'À compléter',
            'tax_number' => '',
            'company_number' => '',
            'legal_status' => '',
            'code_naf' => '',
            'non_assujetti_tva' => '0',
        ];

        $pdf = $this->pdfGenerator->generate($dossier, [
            'shop' => $shop,
            'generated_at' => new \DateTimeImmutable(),
        ]);

        $dossier->setPdfUrl((string) ($pdf['url'] ?? ''));

        $this->em->persist($dossier);
        $this->em->flush();

        return [
            'signature_url' => $signatureUrl,
            'pdf_url' => $dossier->getPdfUrl(),
            'inventory_input_id' => $inputId,
        ];
    }

    private function paySeller(RachatDossier $dossier): void
    {
        $amount = $this->normalizeAmount($dossier->getTotalAchat());

        if ($amount <= 0) {
            throw new \RuntimeException('Le montant total du dossier doit être supérieur à zéro.');
        }

        $storeMeta = $this->hiboutikClient->getDefaultStoreMeta();
        $currency = (string) ($storeMeta['currency_code'] ?? 'EUR');
        $paidMethod = trim((string) ($dossier->getPaidMethod() ?: 'ESP'));

        $comment = sprintf(
            'RACHAT DOSSIER %s / %s %s / %.2f %s / %s',
            $dossier->getReference() ?: ('#' . $dossier->getId()),
            trim((string) $dossier->getNomSnapshot()),
            trim((string) $dossier->getPrenomSnapshot()),
            $amount,
            $currency,
            $paidMethod
        );

        $this->hiboutikClient->tillCashOut(
            $amount,
            $comment,
            $paidMethod,
            $currency
        );

        if (method_exists($dossier, 'setPaidAt')) {
            $dossier->setPaidAt(new \DateTimeImmutable());
        }

        if (!$dossier->getPaidMethod()) {
            $dossier->setPaidMethod($paidMethod);
        }
    }

    private function shouldConvertItemToHib(RachatItem $item): bool
    {
        if (method_exists($item, 'getConvertToHib')) {
            return (bool) $item->getConvertToHib();
        }

        // Tant que le booléen n'existe pas encore sur l'entité,
        // on considère tous les items comme cochés par défaut.
        return true;
    }

    private function ensureHiboutikProductForItem(RachatItem $item, int $supplierId): int
    {
        $existingId = (int) ($item->getHibProductId() ?? 0);
        if ($existingId > 0) {
            return $existingId;
        }

        $price = $this->normalizeAmount($item->getPrixAchat());
        $model = trim((string) ($item->getMarqueModele() ?: $item->getDesignation() ?: ('Rachat item #' . $item->getId())));
        $brandId = method_exists($item, 'getHibBrandId') ? (int) ($item->getHibBrandId() ?? 0) : 0;
        $categoryId = method_exists($item, 'getHibCategoryId') ? (int) ($item->getHibCategoryId() ?? 0) : 0;
        $imei = trim((string) ($item->getImei() ?? ''));

        $payload = [
            'product_model' => $model,
            'product_supplier' => $supplierId,
            'product_supply_price' => $price,
            'product_price' => $price,
            'product_stock_management' => 1,
            'product_display_www' => 0,
            'product_arch' => 0,
            'products_ref_ext' => 'RACHATITEM-' . $item->getId(),
        ];

        if ($brandId > 0) {
            $payload['product_brand'] = $brandId;
        }

        if ($categoryId > 0) {
            $payload['product_category'] = $categoryId;
        }

        $created = $this->hiboutikClient->createProduct($payload);

        if (!($created['ok'] ?? false)) {
            throw new \RuntimeException('Création produit Hiboutik impossible.');
        }

        $productId = (int) (
            $created['data']['product_id']
            ?? $created['product_id']
            ?? 0
        );

        if ($productId <= 0) {
            throw new \RuntimeException('Identifiant produit Hiboutik introuvable après création.');
        }

        if ($imei !== '') {
            try {
                if (method_exists($this->hiboutikClient, 'trySetBarcodeSmart')) {
                    $this->hiboutikClient->trySetBarcodeSmart($productId, $imei);
                }
            } catch (\Throwable) {
                // on ne bloque pas le flux pour un souci de barcode
            }
        }

        try {
            if (method_exists($this->hiboutikClient, 'updateProductAttributes')) {
                $misc = $this->buildMiscTextForItem($item);
                if ($misc !== '') {
                    $this->hiboutikClient->updateProductAttributes($productId, [
                        'product_memo' => $misc,
                    ]);
                }
            }
        } catch (\Throwable) {
            // idem : ne pas bloquer toute la finalisation pour un memo
        }

        return $productId;
    }

    private function buildMiscTextForItem(RachatItem $item): string
    {
        $parts = [];

        if ($item->getDesignation()) {
            $parts[] = 'Désignation : ' . $item->getDesignation();
        }

        if ($item->getImei()) {
            $parts[] = 'IMEI : ' . $item->getImei();
        }

        if (method_exists($item, 'getAttributsJson') && $item->getAttributsJson()) {
            $parts[] = 'Attributs : ' . (string) $item->getAttributsJson();
        }

        return implode("\n", $parts);
    }

    private function normalizeAmount(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $normalized = str_replace("\xc2\xa0", ' ', (string) $value);
        $normalized = str_replace(' ', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }
}