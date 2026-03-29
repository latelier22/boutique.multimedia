<?php

namespace App\Service;

use App\Entity\Rachat\Revendeur;
use Doctrine\ORM\EntityManagerInterface;

class RevendeurHiboutikSync
{
    public function __construct(
        private HiboutikClient $hib,
        private EntityManagerInterface $em
    ) {}

    public function buildHiboutikAddress(Revendeur $v): string
    {
        $l1 = trim((string)$v->getAdresse1());
        $l2 = trim((string)$v->getAdresse2());
        $l3 = trim(trim((string)$v->getCodePostal()) . ' ' . trim((string)$v->getVille()));
        $l4 = trim((string)($v->getPays() ?: 'France'));

        return trim(implode("\n", array_filter([$l1, $l2, $l3, $l4])));
    }

    /**
     * Crée ou retrouve le supplier Hiboutik du revendeur.
     * Retourne supplier_id Hiboutik.
     */
    public function ensureSupplier(Revendeur $v): int
    {
        // ref_ext stable
        if (!$v->getRefExt()) {
            // ref_ext dépend de l'id => flush si besoin
            if (!$v->getId()) {
                $this->em->persist($v);
                $this->em->flush();
            }
            $v->setRefExt('RACHAT-SELLER-' . $v->getId());
            $this->em->flush();
        }

        // Déjà en base locale
        if ($v->getHibSupplierId()) {
            return (int) $v->getHibSupplierId();
        }

        // Existe déjà dans Hiboutik ?
        $existing = $this->hib->findSupplierByRefExt($v->getRefExt());
        if ($existing && !empty($existing['supplier_id'])) {
            $id = (int)$existing['supplier_id'];
            $v->setHibSupplierId($id);
            $this->em->flush();

            // (optionnel) MAJ adresse/contact
            $this->updateSupplierFromRevendeur($id, $v);
            return $id;
        }

        // Créer dans Hiboutik
        $payload = [
            'supplier_name'     => $v->getDisplayName(),
            'supplier_contact'  => $v->getDisplayName(),
            'supplier_email'    => (string)$v->getEmail(),
            'supplier_address'  => $this->buildHiboutikAddress($v),
            'supplier_url'      => '',
            'supplier_enabled'  => 1,
            'supplier_position' => 1,
            'supplier_ref_ext'  => $v->getRefExt(),
        ];

        $created = $this->hib->createSupplier($payload);

        $supplierId = (int)($created['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            throw new \RuntimeException('Hiboutik : création fournisseur impossible (supplier_id absent).');
        }

        $v->setHibSupplierId($supplierId);
        $this->em->flush();

        return $supplierId;
    }

    public function updateSupplierFromRevendeur(int $supplierId, Revendeur $v): void
    {
        $fields = [
            'supplier_name'    => $v->getDisplayName(),
            'supplier_contact' => $v->getDisplayName(),
            'supplier_email'   => (string)$v->getEmail(),
            'supplier_address' => $this->buildHiboutikAddress($v),
            'supplier_enabled' => 1,
        ];

        foreach ($fields as $attr => $val) {
            $this->hib->updateSupplierAttribute($supplierId, $attr, $val);
        }
    }
}
