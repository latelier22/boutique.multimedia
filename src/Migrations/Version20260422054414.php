<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422054414 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE hib_invoice_pointing_row (id INT AUTO_INCREMENT NOT NULL, session_id INT NOT NULL, line_number INT NOT NULL, page_number INT DEFAULT NULL, invoice_number VARCHAR(64) DEFAULT NULL, invoice_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', sku VARCHAR(255) DEFAULT NULL, ean VARCHAR(255) DEFAULT NULL, imei VARCHAR(255) DEFAULT NULL, imeis JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', raw_label LONGTEXT NOT NULL, quantity INT NOT NULL, buy_price DOUBLE PRECISION NOT NULL, total_ht DOUBLE PRECISION DEFAULT NULL, vat VARCHAR(50) DEFAULT NULL, currency_code VARCHAR(8) DEFAULT NULL, match_type VARCHAR(32) DEFAULT NULL, matched_product_id INT DEFAULT NULL, is_pointed TINYINT(1) NOT NULL, pointed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', status VARCHAR(32) NOT NULL, error_message LONGTEXT DEFAULT NULL, raw_data JSON NOT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_F88761BA613FECDF (session_id), INDEX idx_invoice_pointing_row_invoice_number (invoice_number), INDEX idx_invoice_pointing_row_invoice_date (invoice_date), INDEX idx_invoice_pointing_row_sku (sku), INDEX idx_invoice_pointing_row_ean (ean), INDEX idx_invoice_pointing_row_imei (imei), INDEX idx_invoice_pointing_row_matched_product_id (matched_product_id), INDEX idx_invoice_pointing_row_is_pointed (is_pointed), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE hib_invoice_pointing_session (id INT AUTO_INCREMENT NOT NULL, sourceFilename VARCHAR(255) DEFAULT NULL, storedFilename VARCHAR(255) DEFAULT NULL, status VARCHAR(32) NOT NULL, meta JSON NOT NULL COMMENT \'(DC2Type:json)\', createdAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updatedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE hib_invoice_pointing_row ADD CONSTRAINT FK_F88761BA613FECDF FOREIGN KEY (session_id) REFERENCES hib_invoice_pointing_session (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hib_invoice_pointing_row DROP FOREIGN KEY FK_F88761BA613FECDF');
        $this->addSql('DROP TABLE hib_invoice_pointing_row');
        $this->addSql('DROP TABLE hib_invoice_pointing_session');
    }
}
