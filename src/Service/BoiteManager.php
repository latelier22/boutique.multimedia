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

    public function createNext(?string $notes = null): Boite
    {
        $boite = new Boite();
        $boite
            ->setCode($this->boiteRepository->nextCode())
            ->setStatus(Boite::STATUS_AVAILABLE)
            ->setNotes($notes)
            ->touch();

        $this->em->persist($boite);
        $this->em->flush();

        return $boite;
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
}