<?php

namespace App\Service;

use App\Entity\Intervention\Intervention;
use App\Entity\Rachat\Rachat;
use App\Entity\Stock\Boite;
use App\Repository\Stock\BoiteRepository;
use Doctrine\ORM\EntityManagerInterface;

class BoiteManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private BoiteRepository $boiteRepository,
    ) {}

public function createNext(?string $notes = null, ?string $root = null): Boite
{
    $finalRoot = $this->normalizeRoot($root);

    $boite = new Boite();
    $boite
        ->setCode($this->boiteRepository->nextCodeForRoot($finalRoot))
        ->setStatus(Boite::STATUS_AVAILABLE)
        ->setNotes($notes)
        ->touch();

    $this->em->persist($boite);
    $this->em->flush();

    return $boite;
}

private function normalizeRoot(?string $root): string
{
    $root = strtoupper(trim((string) $root));

    if ($root === '') {
        return 'B';
    }

    // enlève les espaces
    $root = preg_replace('/\s+/', '', $root) ?? $root;

    // enlève les chiffres finaux si l'utilisateur tape CASIER01
    $root = preg_replace('/\d+$/', '', $root) ?? $root;

    // ne garde que lettres, underscore, tiret
    $root = preg_replace('/[^A-Z_-]/', '', $root) ?? $root;

    if ($root === '') {
        return 'B';
    }

    return $root;
}
    public function getAvailableOrCurrent(?Boite $current = null): array
    {
        return $this->boiteRepository->findAvailableOrCurrent($current);
    }

    public function assignToIntervention(Boite $boite, Intervention $intervention): void
    {
        if (!$this->canAssign($boite, $intervention->getBoite(), null)) {
            throw new \RuntimeException(sprintf('La boîte %s n’est pas disponible.', $boite->getCode()));
        }

        if ($intervention->getBoite() && $intervention->getBoite() !== $boite) {
            $this->release($intervention->getBoite());
        }

        if ($boite->getRachat() && $boite->getRachat() !== null) {
            throw new \RuntimeException(sprintf('La boîte %s est déjà liée à un rachat.', $boite->getCode()));
        }

        $intervention->setBoite($boite);
        $boite
            ->setStatus(Boite::STATUS_OCCUPIED)
            ->touch();

        $this->em->flush();
    }

    public function assignToRachat(Boite $boite, Rachat $rachat): void
    {
        if (!$this->canAssign($boite, null, $rachat->getBoite())) {
            throw new \RuntimeException(sprintf('La boîte %s n’est pas disponible.', $boite->getCode()));
        }

        if ($rachat->getBoite() && $rachat->getBoite() !== $boite) {
            $this->release($rachat->getBoite());
        }

        if ($boite->getIntervention() && $boite->getIntervention() !== null) {
            throw new \RuntimeException(sprintf('La boîte %s est déjà liée à une intervention.', $boite->getCode()));
        }

        $rachat->setBoite($boite);
        $boite
            ->setStatus(Boite::STATUS_OCCUPIED)
            ->touch();

        $this->em->flush();
    }

    public function release(?Boite $boite): void
    {
        if (!$boite) {
            return;
        }

        if ($boite->getIntervention()) {
            $boite->getIntervention()->setBoite(null);
        }

        if ($boite->getRachat()) {
            $boite->getRachat()->setBoite(null);
        }

        if (method_exists($boite, 'getItems')) {
    foreach ($boite->getItems() as $ri) {
        $ri->setBoite(null);
    }
}

        $boite
            ->setStatus(Boite::STATUS_AVAILABLE)
            ->touch();

        $this->em->flush();
    }

    private function canAssign(Boite $boite, ?Boite $currentInterventionBox, ?Boite $currentRachatBox): bool
    {
        if ($currentInterventionBox && $boite->getId() === $currentInterventionBox->getId()) {
            return true;
        }

        if ($currentRachatBox && $boite->getId() === $currentRachatBox->getId()) {
            return true;
        }

        return $boite->isAvailable();
    }


    public function refreshStatuses(): void
{
    $boites = $this->boiteRepository->findAll();

    foreach ($boites as $boite) {
        $occupied = false;

        if ($boite->getIntervention() !== null) {
            $occupied = true;
        }

        if ($boite->getRachat() !== null) {
            $occupied = true;
        }

        if (method_exists($boite, 'getItems') && !$boite->getItems()->isEmpty()) {
            $occupied = true;
        }

        $boite->setStatus($occupied ? Boite::STATUS_OCCUPIED : Boite::STATUS_AVAILABLE);
        $boite->touch();
    }

    $this->em->flush();
}
}