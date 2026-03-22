<?php

namespace App\Service;

use App\Entity\Rachat\Rachat;
use App\Entity\Rachat\Revendeur;

final class RachatFactory
{
    public function newForRevendeur(Revendeur $revendeur): Rachat
    {
        $r = new Rachat();
        $r->setRevendeur($revendeur);

        // Identité
        $r->setNom($revendeur->getNom());
        $r->setPrenom($revendeur->getPrenom());
        $r->setEmail($revendeur->getEmail());
        $r->setTelephone($revendeur->getTelephone());
        $r->setCodePostal($revendeur->getCodePostal());

        // Adresse concaténée vers le champ unique "adresse" du Rachat
        $adresse = trim(
            ($revendeur->getAdresse1() ?? '') . ' ' .
            ($revendeur->getAdresse2() ?? '') . ' ' .
            ($revendeur->getVille() ?? '')
        );

        $r->setAdresse($adresse !== '' ? $adresse : null);

        // Optionnel : copier automatiquement la CI du revendeur
        if ($revendeur->getCiRectoUrl() || $revendeur->getCiVersoUrl()) {
            $ci = [
                'recto' => $revendeur->getCiRectoUrl(),
                'verso' => $revendeur->getCiVersoUrl(),
            ];
            $r->setPieceIdentiteUrl(json_encode($ci));
        }

        return $this->resetTransactionFields($r);
    }

    public function newFromRachat(Rachat $source): Rachat
    {
        $r = new Rachat();
        $r->setRevendeur($source->getRevendeur());

        // Identité depuis le rachat source
        $r->setNom($source->getNom());
        $r->setPrenom($source->getPrenom());
        $r->setEmail($source->getEmail());
        $r->setTelephone($source->getTelephone());
        $r->setAdresse($source->getAdresse());
        $r->setCodePostal($source->getCodePostal());

        return $this->resetTransactionFields($r);
    }

    private function resetTransactionFields(Rachat $r): Rachat
    {
        // Données produit
        $r->setImei(null);
        $r->setNumeroCi(null);
        $r->setMarqueModele(null);
        $r->setPrixAchat(null);

        // Médias transactionnels
        $r->setSignatureUrl(null);
        $r->setPdfUrl(null);
        $r->setPhotosJson(null);
        $r->setPhoto1(null);
        $r->setPhoto2(null);
        $r->setPhoto3(null);

        // Hiboutik
        $r->setHibProductId(null);

        // Paiement
        $r->setPaidAt(null);
        $r->setPaidMethod(null);

        // Enabled
        $r->setEnabled(true);

        return $r;
    }
}