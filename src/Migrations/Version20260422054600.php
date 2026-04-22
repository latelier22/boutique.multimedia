<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422054600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hib_invoice_pointing_session ADD source_filename VARCHAR(255) DEFAULT NULL, ADD stored_filename VARCHAR(255) DEFAULT NULL, DROP sourceFilename, DROP storedFilename, CHANGE createdAt created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updatedAt updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hib_invoice_pointing_session ADD sourceFilename VARCHAR(255) DEFAULT NULL, ADD storedFilename VARCHAR(255) DEFAULT NULL, DROP source_filename, DROP stored_filename, CHANGE created_at createdAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updatedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}
