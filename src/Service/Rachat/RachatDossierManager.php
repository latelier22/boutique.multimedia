<?php

namespace App\Service\Rachat;

use App\Entity\Rachat\RachatDossier;
use App\Entity\Rachat\RachatItem;
use Doctrine\ORM\EntityManagerInterface;

class RachatDossierManager
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function createDraft(): RachatDossier
    {
        $dossier = new RachatDossier();
        $dossier->setStatus(RachatDossier::STATUS_DRAFT);
        $dossier->setCreatedAt(new \DateTimeImmutable());

        $dossier->setPaidMethod('ESP');

        $item = new RachatItem();
        $item->setOrdre(1);
        $item->setQuantite(1);
        $item->setCreatedAt(new \DateTimeImmutable());

        $dossier->addItem($item);

        return $dossier;
    }

    public function prepareForSave(RachatDossier $dossier): void
    {
        if (!$dossier->getCreatedAt()) {
            $dossier->setCreatedAt(new \DateTimeImmutable());
        }

        $ordre = 1;
        $total = 0.0;
        $hasAtLeastOneItem = false;

        foreach ($dossier->getItems() as $item) {
            $hasAtLeastOneItem = true;

            $item->setOrdre($ordre++);
            if (!$item->getQuantite()) {
                $item->setQuantite(1);
            }
            if (!$item->getCreatedAt()) {
                $item->setCreatedAt($dossier->getCreatedAt());
            }

            $prix = $this->normalizeDecimal($item->getPrixAchat());
            $item->setPrixAchat($prix);

            $total += (float) ($prix ?? 0);
        }

        if (!$hasAtLeastOneItem) {
            $item = new RachatItem();
            $item->setOrdre(1);
            $item->setQuantite(1);
            $item->setCreatedAt($dossier->getCreatedAt());
            $dossier->addItem($item);
        }

        $dossier->setTotalAchat(number_format($total, 2, '.', ''));
    }

    public function generateReferenceIfNeeded(RachatDossier $dossier): void
    {
        if ($dossier->getReference()) {
            return;
        }

        if (!$dossier->getId()) {
            return;
        }

        $date = ($dossier->getCreatedAt() ?? new \DateTimeImmutable())->format('Ymd');
        $id = str_pad((string) $dossier->getId(), 6, '0', STR_PAD_LEFT);

        $dossier->setReference(sprintf('R2-%s-%s', $date, $id));
    }

    public function markSigned(RachatDossier $dossier): void
    {
        $now = new \DateTimeImmutable();

        $dossier->setStatus(RachatDossier::STATUS_SIGNED);
        $dossier->setSignedAt($now);
        $dossier->setLockedAt($now);
    }

    private function normalizeDecimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $v = trim((string) $value);
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

     public function lock(RachatDossier $dossier): void
{
    $dossier->setStatus(RachatDossier::STATUS_LOCKED);

    if (!$dossier->getLockedAt()) {
        $dossier->setLockedAt(new \DateTimeImmutable());
    }
}

 public function unlock(RachatDossier $dossier): void
{
    $dossier->setLockedAt(null);

    if ($dossier->getStatus() === RachatDossier::STATUS_LOCKED) {
        $dossier->setStatus(RachatDossier::STATUS_DRAFT);
    }
}
}