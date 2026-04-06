<?php

namespace App\Service\Rachat;

use App\Entity\Rachat\Rachat;
use App\Entity\Rachat\RachatDossier;
use App\Entity\Rachat\RachatItem;
use Doctrine\ORM\EntityManagerInterface;

class RachatLegacyConverter
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Convertit un ancien Rachat en nouveau RachatDossier + 1 RachatItem.
     *
     * Par défaut :
     * - ne modifie pas l'ancien Rachat
     * - crée un nouveau dossier V2
     * - crée une seule ligne produit à partir des champs legacy
     *
     * Si un dossier V2 existe déjà pour ce legacyRachatId, on le retourne tel quel.
     */
    public function convert(Rachat $legacy, bool $flush = true): RachatDossier
    {
        $existing = $this->em->getRepository(RachatDossier::class)->findOneBy([
            'legacyRachatId' => $legacy->getId(),
        ]);

        if ($existing instanceof RachatDossier) {
            return $existing;
        }

        $dossier = new RachatDossier();

        // ===== Références / statut =====
        $dossier->setLegacyRachatId($legacy->getId());
        $dossier->setReference($this->generateReference($legacy));
        $dossier->setEnabled($legacy->isEnabled());

        // ===== Snapshot client =====
        $dossier->setNomSnapshot($legacy->getNom());
        $dossier->setPrenomSnapshot($legacy->getPrenom());
        $dossier->setTelephoneSnapshot($legacy->getTelephone());
        $dossier->setEmailSnapshot($legacy->getEmail());
        $dossier->setAdresseSnapshot($legacy->getAdresse());
        $dossier->setCodePostalSnapshot($legacy->getCodePostal());
        $dossier->setNumeroCiSnapshot($legacy->getNumeroCi());

        // ===== Documents =====
        $dossier->setPieceIdentiteUrl($legacy->getPieceIdentiteUrl());
        $dossier->setSignatureUrl($legacy->getSignatureUrl());
        $dossier->setPdfUrl($legacy->getPdfUrl());

        // ===== Dates / paiement =====
        $dossier->setDateCession($legacy->getDateCession());
        $dossier->setCreatedAt($legacy->getCreatedAt());
        $dossier->setPaidMethod($legacy->getPaidMethod());
        $dossier->setPaidAt($legacy->getPaidAt());

        // ===== Liens métiers conservés =====
        $dossier->setRevendeur($legacy->getRevendeur());
        $dossier->setBoite($legacy->getBoite());
        $dossier->setHibInventoryInputId($legacy->getHibInventoryInputId());
        $dossier->setHibArrivageAddedAt($legacy->getHibArrivageAddedAt());

        // ===== Statut V2 =====
        // Règle simple :
        // - si signé => signed + verrouillé
        // - sinon => draft
        if ($this->isLegacySigned($legacy)) {
            $dossier->setStatus(RachatDossier::STATUS_SIGNED);
            $dossier->setSignedAt($legacy->getDateCession() ?? $legacy->getCreatedAt() ?? new \DateTimeImmutable());
            $dossier->setLockedAt($legacy->getDateCession() ?? $legacy->getCreatedAt() ?? new \DateTimeImmutable());
        } else {
            $dossier->setStatus(RachatDossier::STATUS_DRAFT);
        }

        // ===== Création de la ligne produit =====
        $item = new RachatItem();

        $item->setLegacyRachatId($legacy->getId());

        $item->setOrdre(1);
        $item->setQuantite(1);

        // Désignation / identification produit
        $item->setDesignation($this->buildDesignation($legacy));
        $item->setMarqueModele($legacy->getMarqueModele());
        $item->setImei($legacy->getImei());

        // Prix
        $normalizedPrice = $this->normalizeDecimal($legacy->getPrixAchat());
        $item->setPrixAchat($normalizedPrice);

        // Hiboutik
        $item->setHibProductId($legacy->getHibProductId());
        $item->setHibBrandId($legacy->getHibBrandId());
        $item->setHibCategoryId($legacy->getHibCategoryId());

        // Attributs / médias
        $item->setAttributes($legacy->getAttributes());
        $item->setPhotosJson($legacy->getPhotosJson());
        $item->setPhoto1($legacy->getPhoto1());
        $item->setPhoto2($legacy->getPhoto2());
        $item->setPhoto3($legacy->getPhoto3());

        // Colonnes legacy
        $item->setColonne1($legacy->getColonne1());
        $item->setColonne2($legacy->getColonne2());
        $item->setColonne3($legacy->getColonne3());
        $item->setColonne4($legacy->getColonne4());

        // Date cohérente si tu veux garder l'historique côté item
        $item->setCreatedAt($legacy->getCreatedAt());

        $dossier->addItem($item);

        // Total dossier
        $dossier->setTotalAchat($normalizedPrice);

        $this->em->persist($dossier);

        if ($flush) {
            $this->em->flush();
        }

        return $dossier;
    }

    private function generateReference(Rachat $legacy): string
    {
        $date = $legacy->getCreatedAt()?->format('Ymd') ?? date('Ymd');
        $id = str_pad((string) ($legacy->getId() ?? 0), 6, '0', STR_PAD_LEFT);

        return 'R2-' . $date . '-' . $id;
    }

    private function isLegacySigned(Rachat $legacy): bool
    {
        return !empty($legacy->getSignatureUrl());
    }

    private function buildDesignation(Rachat $legacy): ?string
    {
        if ($legacy->getMarqueModele()) {
            return $legacy->getMarqueModele();
        }

        $parts = array_filter([
            $legacy->getImei() ? 'Appareil IMEI ' . $legacy->getImei() : null,
        ]);

        if (!empty($parts)) {
            return implode(' - ', $parts);
        }

        return 'Produit issu du rachat legacy #' . $legacy->getId();
    }

    /**
     * Transforme "120", "120.5", "120,50", " 120,50 € " en "120.50"
     */
    private function normalizeDecimal(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $v = trim($value);

        if ($v === '') {
            return null;
        }

        $v = str_replace(['€', ' '], '', $v);
        $v = str_replace(',', '.', $v);

        if (!is_numeric($v)) {
            return null;
        }

        return number_format((float) $v, 2, '.', '');
    }



    public function convertSelectedGrouped(array $legacyIds, bool $flush = true): array
{
    $legacyIds = array_values(array_unique(array_filter(array_map('intval', $legacyIds))));
    if (!$legacyIds) {
        return [
            'created' => [],
            'skippedAlreadyImported' => 0,
            'groups' => 0,
        ];
    }

    $legacies = $this->em->getRepository(Rachat::class)->findBy([
        'id' => $legacyIds,
    ]);

    $groups = [];
    $skippedAlreadyImported = 0;

    foreach ($legacies as $legacy) {
        $existingItem = $this->em->getRepository(RachatItem::class)->findOneBy([
            'legacyRachatId' => $legacy->getId(),
        ]);

        if ($existingItem instanceof RachatItem) {
            $skippedAlreadyImported++;
            continue;
        }

        $groups[$this->buildGroupKey($legacy)][] = $legacy;
    }

    $created = [];

    foreach ($groups as $group) {
        $created[] = $this->convertGroup($group, false);
    }

    if ($flush) {
        $this->em->flush();
    }

    return [
        'created' => $created,
        'skippedAlreadyImported' => $skippedAlreadyImported,
        'groups' => count($groups),
    ];
}

private function buildGroupKey(Rachat $legacy): string
{
    $day = ($legacy->getDateCession() ?? $legacy->getCreatedAt() ?? new \DateTimeImmutable())->format('Y-m-d');
    $revendeurId = $legacy->getRevendeur()?->getId();

    // Pas de revendeur => dossier séparé
    if (!$revendeurId) {
        return 'single:' . $legacy->getId();
    }

    // Même revendeur + même jour => même dossier
    return 'revendeur:' . $revendeurId . ':day:' . $day;
}

private function convertGroup(array $legacies, bool $flush = true): RachatDossier
{
    usort($legacies, function (Rachat $a, Rachat $b) {
        $da = $a->getDateCession() ?? $a->getCreatedAt();
        $db = $b->getDateCession() ?? $b->getCreatedAt();

        return ($da?->getTimestamp() ?? 0) <=> ($db?->getTimestamp() ?? 0);
    });

    if (count($legacies) === 1) {
        return $this->convert($legacies[0], $flush);
    }

    $first = $legacies[0];
    $reference = $this->generateGroupedReference($first);

    $existing = $this->em->getRepository(RachatDossier::class)->findOneBy([
        'reference' => $reference,
    ]);

    if ($existing instanceof RachatDossier) {
        $dossier = $existing;
    } else {
        $dossier = new RachatDossier();

        $dossier->setLegacyRachatId($first->getId());
        $dossier->setReference($reference);
        $dossier->setEnabled($first->isEnabled());

        $dossier->setNomSnapshot($first->getNom());
        $dossier->setPrenomSnapshot($first->getPrenom());
        $dossier->setTelephoneSnapshot($first->getTelephone());
        $dossier->setEmailSnapshot($first->getEmail());
        $dossier->setAdresseSnapshot($first->getAdresse());
        $dossier->setCodePostalSnapshot($first->getCodePostal());
        $dossier->setNumeroCiSnapshot($first->getNumeroCi());

        $dossier->setDateCession($first->getDateCession());
        $dossier->setCreatedAt($first->getCreatedAt());
        $dossier->setPaidMethod($first->getPaidMethod());
        $dossier->setPaidAt($first->getPaidAt());

        $dossier->setRevendeur($first->getRevendeur());
        $dossier->setBoite($first->getBoite());
        $dossier->setHibInventoryInputId($first->getHibInventoryInputId());
        $dossier->setHibArrivageAddedAt($first->getHibArrivageAddedAt());

        if ($this->isLegacySigned($first)) {
            $dossier->setStatus(RachatDossier::STATUS_SIGNED);
            $dossier->setSignedAt($first->getDateCession() ?? $first->getCreatedAt() ?? new \DateTimeImmutable());
            $dossier->setLockedAt($first->getDateCession() ?? $first->getCreatedAt() ?? new \DateTimeImmutable());
        } else {
            $dossier->setStatus(RachatDossier::STATUS_DRAFT);
        }

        $this->em->persist($dossier);
    }

    $mergedCi = $this->mergePieceIdentiteValues(
        $dossier->getPieceIdentiteUrl(),
        ...array_map(fn(Rachat $legacy) => $legacy->getPieceIdentiteUrl(), $legacies)
    );

    $dossier->setPieceIdentiteUrl($mergedCi);
    $dossier->setSignatureUrl(
        $this->firstNonEmptyDocument(
            $legacies,
            fn(Rachat $legacy) => $legacy->getSignatureUrl(),
            $dossier->getSignatureUrl()
        )
    );
    $dossier->setPdfUrl(
        $this->firstNonEmptyDocument(
            $legacies,
            fn(Rachat $legacy) => $legacy->getPdfUrl(),
            $dossier->getPdfUrl()
        )
    );

    $ordre = count($dossier->getItems()) + 1;
    $total = (float) ($dossier->getTotalAchat() ?? 0);

    foreach ($legacies as $legacy) {
        $existingItem = $this->em->getRepository(RachatItem::class)->findOneBy([
            'legacyRachatId' => $legacy->getId(),
        ]);

        if ($existingItem instanceof RachatItem) {
            continue;
        }

        $item = new RachatItem();
        $item->setLegacyRachatId($legacy->getId());
        $item->setOrdre($ordre++);
        $item->setQuantite(1);

        $item->setDesignation($this->buildDesignation($legacy));
        $item->setMarqueModele($legacy->getMarqueModele());
        $item->setImei($legacy->getImei());

        $normalizedPrice = $this->normalizeDecimal($legacy->getPrixAchat());
        $item->setPrixAchat($normalizedPrice);

        $item->setHibProductId($legacy->getHibProductId());
        $item->setHibBrandId($legacy->getHibBrandId());
        $item->setHibCategoryId($legacy->getHibCategoryId());

        $item->setAttributes($legacy->getAttributes());
        $item->setPhotosJson($legacy->getPhotosJson());
        $item->setPhoto1($legacy->getPhoto1());
        $item->setPhoto2($legacy->getPhoto2());
        $item->setPhoto3($legacy->getPhoto3());

        $item->setColonne1($legacy->getColonne1());
        $item->setColonne2($legacy->getColonne2());
        $item->setColonne3($legacy->getColonne3());
        $item->setColonne4($legacy->getColonne4());

        $item->setCreatedAt($legacy->getCreatedAt());

        $dossier->addItem($item);

        $total += (float) ($normalizedPrice ?? 0);
    }

    $dossier->setTotalAchat(number_format($total, 2, '.', ''));

    if ($flush) {
        $this->em->flush();
    }

    return $dossier;
}

private function generateGroupedReference(Rachat $legacy): string
{
    $date = ($legacy->getDateCession() ?? $legacy->getCreatedAt() ?? new \DateTimeImmutable())->format('Ymd');
    $revendeurId = $legacy->getRevendeur()?->getId();

    if ($revendeurId) {
        return sprintf('R2-G-%s-REV-%06d', $date, $revendeurId);
    }

    return sprintf('R2-G-%s-SINGLE-%06d', $date, $legacy->getId());
}

private function normalizePieceIdentite(mixed $rawCi): array
{
    $recto = '';
    $verso = '';

    if (is_string($rawCi) && $rawCi !== '') {
        $decoded = json_decode($rawCi, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            if (!empty($decoded['recto']) && is_string($decoded['recto'])) {
                $recto = $decoded['recto'];
            }
            if (!empty($decoded['verso']) && is_string($decoded['verso'])) {
                $verso = $decoded['verso'];
            }

            if ($recto === '' && !empty($decoded[0]) && is_string($decoded[0])) {
                $recto = $decoded[0];
            }
            if ($verso === '' && !empty($decoded[1]) && is_string($decoded[1])) {
                $verso = $decoded[1];
            }
        } else {
            $recto = $rawCi;
        }
    } elseif (is_array($rawCi)) {
        if (!empty($rawCi['recto']) && is_string($rawCi['recto'])) {
            $recto = $rawCi['recto'];
        }
        if (!empty($rawCi['verso']) && is_string($rawCi['verso'])) {
            $verso = $rawCi['verso'];
        }

        if ($recto === '' && !empty($rawCi[0]) && is_string($rawCi[0])) {
            $recto = $rawCi[0];
        }
        if ($verso === '' && !empty($rawCi[1]) && is_string($rawCi[1])) {
            $verso = $rawCi[1];
        }
    }

    return [
        'recto' => $recto,
        'verso' => $verso,
    ];
}

private function encodePieceIdentite(array $ci): ?string
{
    $recto = trim((string) ($ci['recto'] ?? ''));
    $verso = trim((string) ($ci['verso'] ?? ''));

    if ($recto === '' && $verso === '') {
        return null;
    }

    if ($recto !== '' && $verso === '') {
        return $recto;
    }

    return json_encode([
        'recto' => $recto,
        'verso' => $verso,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

private function mergePieceIdentiteValues(mixed ...$values): ?string
{
    $merged = [
        'recto' => '',
        'verso' => '',
    ];

    foreach ($values as $value) {
        $ci = $this->normalizePieceIdentite($value);

        if ($merged['recto'] === '' && !empty($ci['recto'])) {
            $merged['recto'] = $ci['recto'];
        }

        if ($merged['verso'] === '' && !empty($ci['verso'])) {
            $merged['verso'] = $ci['verso'];
        }
    }

    return $this->encodePieceIdentite($merged);
}

private function firstNonEmptyDocument(array $legacies, callable $getter, ?string $currentValue = null): ?string
{
    if (!empty($currentValue)) {
        return $currentValue;
    }

    foreach ($legacies as $legacy) {
        $value = $getter($legacy);
        if (!empty($value)) {
            return $value;
        }
    }

    return null;
}

public function resyncDossier(\App\Entity\Rachat\RachatDossier $dossier, bool $flush = true): \App\Entity\Rachat\RachatDossier
{
    $legacyIds = [];

    if ($dossier->getLegacyRachatId()) {
        $legacyIds[] = (int) $dossier->getLegacyRachatId();
    }

    foreach ($dossier->getItems() as $item) {
        if ($item->getLegacyRachatId()) {
            $legacyIds[] = (int) $item->getLegacyRachatId();
        }
    }

    $legacyIds = array_values(array_unique(array_filter($legacyIds)));

    if (!$legacyIds) {
        return $dossier;
    }

    $legacies = $this->em->getRepository(\App\Entity\Rachat\Rachat::class)->findBy(['id' => $legacyIds]);

    if (!$legacies) {
        return $dossier;
    }

    usort($legacies, function ($a, $b) {
        $da = $a->getDateCession() ?? $a->getCreatedAt();
        $db = $b->getDateCession() ?? $b->getCreatedAt();
        return ($da?->getTimestamp() ?? 0) <=> ($db?->getTimestamp() ?? 0);
    });

    $first = $legacies[0];

    $dossier->setNomSnapshot($first->getNom());
    $dossier->setPrenomSnapshot($first->getPrenom());
    $dossier->setTelephoneSnapshot($first->getTelephone());
    $dossier->setEmailSnapshot($first->getEmail());
    $dossier->setAdresseSnapshot($first->getAdresse());
    $dossier->setCodePostalSnapshot($first->getCodePostal());
    $dossier->setNumeroCiSnapshot($first->getNumeroCi());

    $pieceIdentiteUrl = null;
    $signatureUrl = $dossier->getSignatureUrl();
    $pdfUrl = $dossier->getPdfUrl();

    foreach ($legacies as $legacy) {
        if (!$pieceIdentiteUrl && $legacy->getPieceIdentiteUrl()) {
            $pieceIdentiteUrl = $legacy->getPieceIdentiteUrl();
        }
        if (!$signatureUrl && $legacy->getSignatureUrl()) {
            $signatureUrl = $legacy->getSignatureUrl();
        }
        if (!$pdfUrl && $legacy->getPdfUrl()) {
            $pdfUrl = $legacy->getPdfUrl();
        }
    }

    $dossier->setPieceIdentiteUrl($pieceIdentiteUrl);
    $dossier->setSignatureUrl($signatureUrl);
    $dossier->setPdfUrl($pdfUrl);

    $total = 0.0;
    foreach ($dossier->getItems() as $item) {
        $total += (float) ($item->getPrixAchat() ?? 0);
    }
    $dossier->setTotalAchat(number_format($total, 2, '.', ''));

    if ($flush) {
        $this->em->flush();
    }

    return $dossier;
}
}