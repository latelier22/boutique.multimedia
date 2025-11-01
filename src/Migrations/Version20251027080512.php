<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251027080512 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE rachat ADD imei_serie VARCHAR(255) DEFAULT NULL, ADD numero_ci VARCHAR(255) DEFAULT NULL, ADD prix_achat VARCHAR(255) DEFAULT NULL, ADD nom VARCHAR(255) DEFAULT NULL, ADD prenom VARCHAR(255) DEFAULT NULL, ADD telephone VARCHAR(255) DEFAULT NULL, ADD marque_modele VARCHAR(255) DEFAULT NULL, ADD piece_identite_url VARCHAR(1024) DEFAULT NULL, ADD date_de_cession VARCHAR(255) DEFAULT NULL, ADD adresse VARCHAR(1024) DEFAULT NULL, ADD code_postal VARCHAR(64) DEFAULT NULL, ADD email VARCHAR(255) DEFAULT NULL, ADD signature_url VARCHAR(1024) DEFAULT NULL, ADD pdf_url VARCHAR(1024) DEFAULT NULL, ADD photos_produit_urls_json VARCHAR(2048) DEFAULT NULL, ADD photo_produit_1 VARCHAR(1024) DEFAULT NULL, ADD photo_produit_2 VARCHAR(1024) DEFAULT NULL, ADD photo_produit_3 VARCHAR(1024) DEFAULT NULL, ADD colonne_1 VARCHAR(1024) DEFAULT NULL, ADD colonne_2 VARCHAR(1024) DEFAULT NULL, ADD colonne_3 VARCHAR(1024) DEFAULT NULL, ADD colonne_4 VARCHAR(1024) DEFAULT NULL, DROP createdAt, DROP updatedAt, DROP produit, DROP marque, DROP modele, DROP stockage, DROP couleur, DROP etat, DROP imei, DROP serialNumber, DROP fournisseur, DROP fournisseurId, DROP fournisseurContact, DROP prixAchat, DROP prixVente, DROP devise, DROP urlPf, DROP urlPdf, DROP photo1, DROP photo2, DROP photo3, DROP photo4, DROP photos, DROP notes, DROP hiboutikProductId, DROP hiboutikArrivalId, DROP syncStatus, DROP extra, CHANGE id id INT NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE rachat ADD createdAt DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', ADD updatedAt DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD produit VARCHAR(255) NOT NULL, ADD marque VARCHAR(255) DEFAULT NULL, ADD modele VARCHAR(255) DEFAULT NULL, ADD couleur VARCHAR(64) DEFAULT NULL, ADD etat VARCHAR(64) DEFAULT NULL, ADD imei VARCHAR(64) DEFAULT NULL, ADD serialNumber VARCHAR(64) DEFAULT NULL, ADD fournisseur VARCHAR(255) DEFAULT NULL, ADD fournisseurId INT DEFAULT NULL, ADD fournisseurContact VARCHAR(255) DEFAULT NULL, ADD prixAchat DOUBLE PRECISION DEFAULT NULL, ADD prixVente DOUBLE PRECISION DEFAULT NULL, ADD devise VARCHAR(8) DEFAULT 'EUR' NOT NULL, ADD urlPf VARCHAR(1024) DEFAULT NULL, ADD urlPdf VARCHAR(1024) DEFAULT NULL, ADD photo1 VARCHAR(1024) DEFAULT NULL, ADD photo2 VARCHAR(1024) DEFAULT NULL, ADD photo3 VARCHAR(1024) DEFAULT NULL, ADD photo4 VARCHAR(1024) DEFAULT NULL, ADD photos JSON DEFAULT NULL COMMENT '(DC2Type:json)', ADD notes LONGTEXT DEFAULT NULL, ADD hiboutikProductId INT DEFAULT NULL, ADD hiboutikArrivalId INT DEFAULT NULL, ADD syncStatus VARCHAR(32) DEFAULT 'draft' NOT NULL, ADD extra JSON DEFAULT NULL COMMENT '(DC2Type:json)', DROP imei_serie, DROP numero_ci, DROP prix_achat, DROP nom, DROP prenom, DROP telephone, DROP marque_modele, DROP piece_identite_url, DROP date_de_cession, DROP adresse, DROP email, DROP signature_url, DROP pdf_url, DROP photos_produit_urls_json, DROP photo_produit_1, DROP photo_produit_2, DROP photo_produit_3, DROP colonne_1, DROP colonne_2, DROP colonne_3, DROP colonne_4, CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE code_postal stockage VARCHAR(64) DEFAULT NULL
        SQL);
    }
}
