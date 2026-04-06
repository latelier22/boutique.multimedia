<?php

namespace App\Service\Rachat;

use App\Entity\Rachat\RachatDossier;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\HiboutikClient;

class RachatDossierCustomerResolver
{
    public function __construct(
        private HiboutikClient $hib,
        private EntityManagerInterface $em,
    ) {
    }

    public function searchCandidates(RachatDossier $dossier): array
    {
        return $this->searchCandidatesFromData(
            $dossier->getTelephoneSnapshot(),
            $dossier->getEmailSnapshot(),
            $dossier->getNomSnapshot(),
            $dossier->getPrenomSnapshot(),
        );
    }

    public function searchCandidatesFromData(
        ?string $phone,
        ?string $email,
        ?string $lastName,
        ?string $firstName,
    ): array {
        return $this->hib->searchCustomersLocal($phone, $email, $lastName, $firstName);
    }

    public function assignExistingCustomer(RachatDossier $dossier, int $hibCustomerId): void
    {
        $dossier->setHibCustomerId($hibCustomerId);
        $dossier->setCustomerLinkStatus(RachatDossier::CUSTOMER_MATCHED);
        $dossier->setCustomerLinkNote('Client Hiboutik affecté');
        $this->em->flush();
    }

   public function createCustomerFromDossier(RachatDossier $dossier): int
{
    $payload = [
        'first_name' => (string) $dossier->getPrenomSnapshot(),
        'last_name'  => (string) $dossier->getNomSnapshot(),
        'phone'      => (string) $dossier->getTelephoneSnapshot(),
        'country'    => 'FRA',
        'customers_misc' => sprintf(
            'Créé depuis dossier rachat V2 #%d',
            (int) $dossier->getId()
        ),
    ];

    $email = trim((string) $dossier->getEmailSnapshot());
    if ($email !== '') {
        $payload['email'] = $email;
    }

    $res = $this->hib->createCustomer($payload);

    $customerId = (int) ($res['customer_id'] ?? 0);

    if (!($res['ok'] ?? false) || $customerId <= 0) {
        throw new \RuntimeException(
            'Impossible de créer le client Hiboutik. DEBUG: ' .
            json_encode([
                'result' => $res,
                'lastDebug' => $this->hib->getLastDebug(),
            ], JSON_UNESCAPED_UNICODE)
        );
    }

    $dossier->setHibCustomerId($customerId);
    $dossier->setCustomerLinkStatus(RachatDossier::CUSTOMER_CREATED);
    $dossier->setCustomerLinkNote('Client Hiboutik créé depuis le dossier');
    $this->em->flush();

    return $customerId;
}

 public function assignVirtualCustomer(RachatDossier $dossier): int
{
    $res = $this->hib->ensureVirtualCustomer('RACHAT_VIRTUEL');

    $customerId = (int)($res['customer_id'] ?? 0);

    if (!($res['ok'] ?? false) || $customerId <= 0) {
        throw new \RuntimeException(
            'Impossible d’affecter le client virtuel. DEBUG: ' .
            json_encode([
                'result' => $res,
                'lastDebug' => $this->hib->getLastDebug(),
            ], JSON_UNESCAPED_UNICODE)
        );
    }

    $dossier->setHibCustomerId($customerId);
    $dossier->setCustomerLinkStatus(RachatDossier::CUSTOMER_VIRTUAL);
    $dossier->setCustomerLinkNote('Client virtuel affecté');
    $this->em->flush();

    return $customerId;
}

public function findExactPhoneCandidateId(RachatDossier $dossier): ?int
{
    $phone = $this->hib->normalizePhone((string) $dossier->getTelephoneSnapshot());

    if ($phone === '') {
        return null;
    }

    $candidates = $this->searchCandidates($dossier);

    foreach ($candidates as $candidate) {
        $candidateId = (int) ($candidate['id'] ?? 0);
        $candidatePhone = $this->hib->normalizePhone((string) ($candidate['phone'] ?? ''));

        if ($candidateId > 0 && $candidatePhone !== '' && $candidatePhone === $phone) {
            return $candidateId;
        }
    }

    return null;
}

public function assignByPhoneOrCreate(RachatDossier $dossier): array
{
    $existingCustomerId = $this->findExactPhoneCandidateId($dossier);

    if ($existingCustomerId) {
        $this->assignExistingCustomer($dossier, $existingCustomerId);

        return [
            'mode' => 'assigned',
            'customer_id' => $existingCustomerId,
        ];
    }

    $createdCustomerId = $this->createCustomerFromDossier($dossier);

    return [
        'mode' => 'created',
        'customer_id' => $createdCustomerId,
    ];
}
}