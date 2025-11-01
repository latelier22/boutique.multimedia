<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251027074526 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE rachat (id INT AUTO_INCREMENT NOT NULL, createdAt DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updatedAt DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', produit VARCHAR(255) NOT NULL, marque VARCHAR(255) DEFAULT NULL, modele VARCHAR(255) DEFAULT NULL, stockage VARCHAR(64) DEFAULT NULL, couleur VARCHAR(64) DEFAULT NULL, etat VARCHAR(64) DEFAULT NULL, imei VARCHAR(64) DEFAULT NULL, serialNumber VARCHAR(64) DEFAULT NULL, fournisseur VARCHAR(255) DEFAULT NULL, fournisseurId INT DEFAULT NULL, fournisseurContact VARCHAR(255) DEFAULT NULL, prixAchat DOUBLE PRECISION DEFAULT NULL, prixVente DOUBLE PRECISION DEFAULT NULL, devise VARCHAR(8) DEFAULT 'EUR' NOT NULL, urlPf VARCHAR(1024) DEFAULT NULL, urlPdf VARCHAR(1024) DEFAULT NULL, photo1 VARCHAR(1024) DEFAULT NULL, photo2 VARCHAR(1024) DEFAULT NULL, photo3 VARCHAR(1024) DEFAULT NULL, photo4 VARCHAR(1024) DEFAULT NULL, photos JSON DEFAULT NULL COMMENT '(DC2Type:json)', notes LONGTEXT DEFAULT NULL, hiboutikProductId INT DEFAULT NULL, hiboutikArrivalId INT DEFAULT NULL, syncStatus VARCHAR(32) DEFAULT 'draft' NOT NULL, extra JSON DEFAULT NULL COMMENT '(DC2Type:json)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            DROP TABLE rachat
        SQL);
    }
}
