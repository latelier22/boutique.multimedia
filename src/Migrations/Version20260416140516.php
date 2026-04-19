<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260416140516 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hib_incoming_supplier_document ADD documentType VARCHAR(10) DEFAULT NULL, ADD documentNumber VARCHAR(255) DEFAULT NULL, ADD documentDate DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', ADD supplierName VARCHAR(255) DEFAULT NULL, ADD processingStatus VARCHAR(32) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hib_incoming_supplier_document DROP documentType, DROP documentNumber, DROP documentDate, DROP supplierName, DROP processingStatus');
    }
}
