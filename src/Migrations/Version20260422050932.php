<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422050932 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hib_mobile_import_row ADD invoiceNumber VARCHAR(64) DEFAULT NULL, ADD invoiceDate DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', ADD pageNumber INT DEFAULT NULL, ADD imeis JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', ADD pointingMatchType VARCHAR(32) DEFAULT NULL, ADD pointedProductId INT DEFAULT NULL, ADD isPointed TINYINT(1) NOT NULL, ADD pointedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hib_mobile_import_row DROP invoiceNumber, DROP invoiceDate, DROP pageNumber, DROP imeis, DROP pointingMatchType, DROP pointedProductId, DROP isPointed, DROP pointedAt');
    }
}
