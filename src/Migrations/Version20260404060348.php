<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260404060348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE rachat_dossiers (id INT AUTO_INCREMENT NOT NULL, revendeur_id INT DEFAULT NULL, boite_id INT DEFAULT NULL, reference VARCHAR(64) DEFAULT NULL, status VARCHAR(32) DEFAULT \'draft\' NOT NULL, legacy_rachat_id INT DEFAULT NULL, hib_customer_id INT DEFAULT NULL, nom_snapshot VARCHAR(255) DEFAULT NULL, prenom_snapshot VARCHAR(255) DEFAULT NULL, telephone_snapshot VARCHAR(255) DEFAULT NULL, email_snapshot VARCHAR(255) DEFAULT NULL, adresse_snapshot LONGTEXT DEFAULT NULL, code_postal_snapshot VARCHAR(64) DEFAULT NULL, ville_snapshot VARCHAR(255) DEFAULT NULL, numero_ci_snapshot VARCHAR(255) DEFAULT NULL, piece_identite_url LONGTEXT DEFAULT NULL, signature_url LONGTEXT DEFAULT NULL, pdf_url LONGTEXT DEFAULT NULL, date_cession DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', total_achat NUMERIC(10, 2) DEFAULT NULL, paid_method VARCHAR(32) DEFAULT NULL, paid_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', signed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', locked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', cancelled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', cancel_reason LONGTEXT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, enabled TINYINT(1) DEFAULT 1 NOT NULL, hib_inventory_input_id INT DEFAULT NULL, hib_arrivage_added_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_F003B5D3AEA34913 (reference), INDEX IDX_F003B5D3F4218D56 (revendeur_id), UNIQUE INDEX UNIQ_F003B5D33C43472D (boite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE rachat_items (id INT AUTO_INCREMENT NOT NULL, dossier_id INT NOT NULL, ordre INT DEFAULT 0 NOT NULL, quantite INT DEFAULT 1 NOT NULL, designation VARCHAR(255) DEFAULT NULL, categorie VARCHAR(255) DEFAULT NULL, etat VARCHAR(255) DEFAULT NULL, marque VARCHAR(255) DEFAULT NULL, modele VARCHAR(255) DEFAULT NULL, marque_modele VARCHAR(255) DEFAULT NULL, imei VARCHAR(255) DEFAULT NULL, numero_serie VARCHAR(255) DEFAULT NULL, variante VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, observations LONGTEXT DEFAULT NULL, prix_achat NUMERIC(10, 2) DEFAULT NULL, hib_product_id INT DEFAULT NULL, hib_brand_id INT DEFAULT NULL, hib_category_id INT DEFAULT NULL, attributes JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', photos_json LONGTEXT DEFAULT NULL, photo1 LONGTEXT DEFAULT NULL, photo2 LONGTEXT DEFAULT NULL, photo3 LONGTEXT DEFAULT NULL, colonne1 LONGTEXT DEFAULT NULL, colonne2 LONGTEXT DEFAULT NULL, colonne3 LONGTEXT DEFAULT NULL, colonne4 LONGTEXT DEFAULT NULL, enabled TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_2117FC5C611C0C56 (dossier_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE rachat_dossiers ADD CONSTRAINT FK_F003B5D3F4218D56 FOREIGN KEY (revendeur_id) REFERENCES revendeurs (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE rachat_dossiers ADD CONSTRAINT FK_F003B5D33C43472D FOREIGN KEY (boite_id) REFERENCES app_boite (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE rachat_items ADD CONSTRAINT FK_2117FC5C611C0C56 FOREIGN KEY (dossier_id) REFERENCES rachat_dossiers (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rachat_dossiers DROP FOREIGN KEY FK_F003B5D3F4218D56');
        $this->addSql('ALTER TABLE rachat_dossiers DROP FOREIGN KEY FK_F003B5D33C43472D');
        $this->addSql('ALTER TABLE rachat_items DROP FOREIGN KEY FK_2117FC5C611C0C56');
        $this->addSql('DROP TABLE rachat_dossiers');
        $this->addSql('DROP TABLE rachat_items');
    }
}
