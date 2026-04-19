<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260416130955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE hib_incoming_supplier_attachment (id INT AUTO_INCREMENT NOT NULL, document_id INT NOT NULL, originalFilename VARCHAR(255) NOT NULL, storedFilename VARCHAR(255) NOT NULL, mimeType VARCHAR(100) DEFAULT NULL, size INT NOT NULL, isParsable TINYINT(1) NOT NULL, status VARCHAR(32) NOT NULL, notes LONGTEXT DEFAULT NULL, INDEX IDX_18BE47DBC33F7837 (document_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE hib_incoming_supplier_document (id INT AUTO_INCREMENT NOT NULL, messageId VARCHAR(255) DEFAULT NULL, fromEmail VARCHAR(255) DEFAULT NULL, fromName VARCHAR(255) DEFAULT NULL, subject VARCHAR(255) DEFAULT NULL, receivedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', status VARCHAR(32) NOT NULL, detectedType VARCHAR(32) DEFAULT NULL, detectedDocumentCode VARCHAR(255) DEFAULT NULL, detectedDocumentDate DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', hasImei TINYINT(1) NOT NULL, detectedSupplierId INT DEFAULT NULL, importSessionId INT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, meta JSON NOT NULL COMMENT \'(DC2Type:json)\', createdAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE hib_incoming_supplier_attachment ADD CONSTRAINT FK_18BE47DBC33F7837 FOREIGN KEY (document_id) REFERENCES hib_incoming_supplier_document (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hib_incoming_supplier_attachment DROP FOREIGN KEY FK_18BE47DBC33F7837');
        $this->addSql('DROP TABLE hib_incoming_supplier_attachment');
        $this->addSql('DROP TABLE hib_incoming_supplier_document');
    }
}
