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

    public function finalizeFromTabletSignature(
        RachatDossier $dossier,
        string $dataUrl,
        bool $acceptedConditions = true
    ): array {
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
            $this->em->flush();
        }

        $signatureUrl = $this->signatureManager->storeSignatureDataUrl($dossier, $dataUrl);

        $storeMeta = $this->hiboutikClient->getDefaultStoreMeta();
        $stockId = (int) ($storeMeta['stock_id'] ?? $storeMeta['store_id'] ?? 1);

        $supplierId = 3;

        $inputId = (int) ($dossier->getHibInventoryInputId() ?? 0);

        if ($inputId <= 0) {
            $resInput = $this->hiboutikClient->getOrCreateMonthlyRachatInput(
                $stockId,
                $supplierId,
                new \DateTimeImmutable('today')
            );

            if (!is_array($resInput) || !($resInput['ok'] ?? false) || empty($resInput['id'])) {
                throw new \RuntimeException('Impossible de créer ou récupérer l’arrivage mensuel Hiboutik.');
            }

            $inputId = (int) $resInput['id'];
            $dossier->setHibInventoryInputId($inputId);
        }

        if ($dossier->getHibArrivageAddedAt() === null) {
    foreach ($dossier->getItems() as $item) {
        if (!$this->shouldConvertItemToHib($item)) {
            continue;
        }

        if ($item->getHibArrivageAddedAt() !== null && (int) $item->getHibInventoryInputId() > 0) {
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

        $resAdd = $this->hiboutikClient->addProductToInventoryInput(
            $inputId,
            $productId,
            1,
            $unitPrice
        );

        if (!($resAdd['ok'] ?? false)) {
            throw new \RuntimeException(sprintf(
                'Erreur ajout à l’arrivage mensuel pour l’item #%d.',
                (int) $item->getId()
            ));
        }

        $item->setHibInventoryInputId($inputId);
        $item->setHibArrivageAddedAt(new \DateTimeImmutable());
    }

    $dossier->setHibArrivageAddedAt(new \DateTimeImmutable());
}

        $this->paySeller($dossier);

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
        if ($dossier->getPaidAt() !== null) {
            return;
        }

        $amount = $this->resolveDossierAmount($dossier);

        if ($amount <= 0) {
            throw new \RuntimeException('Le montant total du dossier doit être supérieur à zéro.');
        }

        $storeMeta = $this->hiboutikClient->getDefaultStoreMeta();
        $storeId = (int) ($storeMeta['store_id'] ?? 1);
        $currency = (string) ($storeMeta['currency_code'] ?? 'EUR');
        $paidMethod = trim((string) ($dossier->getPaidMethod() ?: 'ESP'));

        $comment = $this->buildCashOutComment($dossier, $amount, $currency, $paidMethod);

        $res = $this->hiboutikClient->tillCashOut(
            $storeId,
            $amount,
            $currency,
            $comment
        );

        $ok = ($res['ok'] ?? false) || !empty($res['data']['till_id'] ?? null);

        if (!$ok) {
            throw new \RuntimeException('Erreur Hiboutik lors de l’encaissement vendeur.');
        }

        $dossier->setPaidAt(new \DateTimeImmutable());
        $dossier->setPaidMethod($paidMethod);
    }

    private function shouldConvertItemToHib(RachatItem $item): bool
    {
        if (method_exists($item, 'isConvertToHib')) {
            return (bool) $item->isConvertToHib();
        }

        if (method_exists($item, 'getConvertToHib')) {
            return (bool) $item->getConvertToHib();
        }

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
    $brandId = (int) ($item->getHibBrandId() ?? 0);
    $categoryId = (int) ($item->getHibCategoryId() ?? 0);
    $imei = trim((string) ($item->getImei() ?? ''));

    $payload = [
        'product_model' => $model,
        'product_supplier' => $supplierId,
        'product_supply_price' => $price,
        'product_price' => $price,
        'product_stock_management' => 1,
        'product_display_www' => 0,
        'product_arch' => 0,
        'products_ref_ext' => 'RACHAT-DOSSIER-' . ($item->getDossier()?->getId() ?? 0) . '-ITEM-' . $item->getId(),
    ];

    if ($brandId > 0) {
        $payload['product_brand'] = $brandId;
    }

    if ($categoryId > 0) {
        $payload['product_category'] = $categoryId;
    }

    $created = $this->hiboutikClient->createProduct($payload);
    error_log('[HIB CREATE PRODUCT] ' . json_encode($created, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    // On ne se fie pas uniquement à "ok"
    $data = is_array($created) ? ($created['data'] ?? $created) : null;

    $productId = 0;

    if (is_array($data)) {
        if (isset($data['product_id'])) {
            $productId = (int) $data['product_id'];
        } elseif (isset($data[0]) && is_array($data[0]) && isset($data[0]['product_id'])) {
            $productId = (int) $data[0]['product_id'];
        }
    }

    if ($productId <= 0 && isset($created['product_id'])) {
        $productId = (int) $created['product_id'];
    }

    if ($productId <= 0) {
        throw new \RuntimeException('Identifiant produit Hiboutik introuvable après création.');
    }

    if ($imei !== '' && method_exists($this->hiboutikClient, 'putProductAttributeSingle')) {
    try {
        $this->hiboutikClient->putProductAttributeSingle(
            $productId,
            'product_barcode',
            $imei
        );
    } catch (\Throwable) {
        // Ne pas bloquer toute la finalisation pour un souci de barcode
    }
}

    if (method_exists($this->hiboutikClient, 'updateProductAttributes')) {
        try {
            $misc = $this->buildMiscTextForItem($item);
            if ($misc !== '') {
                $this->hiboutikClient->updateProductAttributes($productId, [
                    'misc_text' => $misc,
                ]);
            }
        } catch (\Throwable) {
            // Ne pas bloquer toute la finalisation pour le misc_text
        }
    }

    return $productId;
}

    private function buildMiscTextForItem(RachatItem $item): string
    {
        $parts = [];

        if ($item->getDesignation()) {
            $parts[] = 'Désignation : ' . $item->getDesignation();
        }

        if ($item->getMarqueModele()) {
            $parts[] = 'Marque / modèle : ' . $item->getMarqueModele();
        }

        if ($item->getImei()) {
            $parts[] = 'IMEI : ' . $item->getImei();
        }

        $attributes = $item->getAttributes();
        if (!empty($attributes)) {
            $parts[] = 'Attributs : ' . json_encode(
                $attributes,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        return implode("\n", $parts);
    }

    private function buildCashOutComment(
        RachatDossier $dossier,
        float $amount,
        string $currency,
        string $paidMethod
    ): string {
        $labels = [];

        foreach ($dossier->getItems() as $item) {
            $label = trim((string) ($item->getMarqueModele() ?: $item->getDesignation() ?: $item->getLabel()));
            $price = $this->normalizeAmount($item->getPrixAchat());

            if ($label !== '') {
                $labels[] = sprintf('%s %s€', $label, number_format($price, 2, ',', ' '));
            }
        }

        if (count($labels) > 5) {
            $labels = array_slice($labels, 0, 5);
            $labels[] = '...';
        }

        return sprintf(
            'RACHAT DOSSIER %s / %s %s / %s / %.2f %s / %s',
            $dossier->getReference() ?: ('#' . $dossier->getId()),
            trim((string) $dossier->getNomSnapshot()),
            trim((string) $dossier->getPrenomSnapshot()),
            implode(' / ', $labels),
            $amount,
            $currency,
            $paidMethod
        );
    }

    private function resolveDossierAmount(RachatDossier $dossier): float
    {
        $amount = $this->normalizeAmount($dossier->getTotalAchat());

        if ($amount > 0) {
            return round($amount, 2);
        }

        $sum = 0.0;

        foreach ($dossier->getItems() as $item) {
            $price = $this->normalizeAmount($item->getPrixAchat());
            $qty = max(1, (int) ($item->getQuantite() ?? 1));
            $sum += $price * $qty;
        }

        return round($sum, 2);
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