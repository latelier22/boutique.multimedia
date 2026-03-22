<?php

namespace App\Service;

use App\Entity\Intervention\AppCounter;
use App\Entity\Intervention\Intervention;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

class InterventionNumberGenerator
{
    public function __construct(
        private EntityManagerInterface $em,
        private int $startAt = 2001,
    ) {}

    public function next(): int
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            $counter = $this->getOrCreateCounter();
            $this->em->lock($counter, LockMode::PESSIMISTIC_WRITE);

            $num = $counter->getNextValue();
            $counter->setNextValue($num + 1);

            $this->em->flush();
            $conn->commit();

            return $num;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    public function validateManualNumber(int $number, ?int $ignoreInterventionId = null): void
    {
        if ($number <= 0) {
            throw new \InvalidArgumentException('Numéro invalide.');
        }

        $existing = $this->em->getRepository(Intervention::class)
            ->findOneBy(['interventionNumber' => $number]);

        if ($existing && $existing->getId() !== $ignoreInterventionId) {
            throw new \RuntimeException('Ce numéro d’intervention existe déjà.');
        }
    }

    public function bumpCounterIfNeeded(int $number): void
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            $counter = $this->getOrCreateCounter();
            $this->em->lock($counter, LockMode::PESSIMISTIC_WRITE);

            if ($number >= $counter->getNextValue()) {
                $counter->setNextValue($number + 1);
                $this->em->flush();
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    private function getOrCreateCounter(): AppCounter
    {
        $repo = $this->em->getRepository(AppCounter::class);
        $counter = $repo->findOneBy(['code' => 'intervention']);

        if (!$counter) {
            $counter = new AppCounter();
            $counter->setCode('intervention');
            $counter->setNextValue($this->startAt);
            $this->em->persist($counter);
            $this->em->flush();
        }

        return $counter;
    }
}