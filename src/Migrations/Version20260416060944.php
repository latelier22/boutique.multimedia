<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260416060944 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE hib_mobile_import_row (id INT AUTO_INCREMENT NOT NULL, session_id INT NOT NULL, lineNumber INT NOT NULL, unitIndex INT DEFAULT NULL, sku VARCHAR(255) DEFAULT NULL, ean VARCHAR(255) DEFAULT NULL, rawLabel VARCHAR(255) NOT NULL, imei VARCHAR(32) DEFAULT NULL, quantity INT NOT NULL, buyPrice DOUBLE PRECISION NOT NULL, currencyCode VARCHAR(8) DEFAULT NULL, parsedBrand VARCHAR(255) DEFAULT NULL, parsedModel VARCHAR(255) DEFAULT NULL, parsedStorage VARCHAR(64) DEFAULT NULL, parsedColor VARCHAR(64) DEFAULT NULL, parsedGrade VARCHAR(16) DEFAULT NULL, requiresImei TINYINT(1) NOT NULL, baseProductId INT DEFAULT NULL, createdProductId INT DEFAULT NULL, status VARCHAR(32) NOT NULL, errorMessage LONGTEXT DEFAULT NULL, rawData JSON NOT NULL COMMENT \'(DC2Type:json)\', INDEX IDX_8FE45828613FECDF (session_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE hib_mobile_import_session (id INT AUTO_INCREMENT NOT NULL, sourceType VARCHAR(32) NOT NULL, sourceFilename VARCHAR(255) NOT NULL, storedFilename VARCHAR(255) DEFAULT NULL, documentCode VARCHAR(255) DEFAULT NULL, supplierId INT DEFAULT NULL, hibInventoryInputId INT DEFAULT NULL, status VARCHAR(32) NOT NULL, meta JSON NOT NULL COMMENT \'(DC2Type:json)\', createdAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE hib_mobile_import_row ADD CONSTRAINT FK_8FE45828613FECDF FOREIGN KEY (session_id) REFERENCES hib_mobile_import_session (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hib_mobile_import_row DROP FOREIGN KEY FK_8FE45828613FECDF');
        $this->addSql('DROP TABLE hib_mobile_import_row');
        $this->addSql('DROP TABLE hib_mobile_import_session');
    }
}
