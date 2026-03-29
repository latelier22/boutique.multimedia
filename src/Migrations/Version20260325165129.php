<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260325165129 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_boite (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, notes LONGTEXT DEFAULT NULL, createdAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updatedAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_A1CB602277153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE app_intervention ADD boite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_intervention ADD CONSTRAINT FK_13960E2A3C43472D FOREIGN KEY (boite_id) REFERENCES app_boite (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_13960E2A3C43472D ON app_intervention (boite_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_intervention DROP FOREIGN KEY FK_13960E2A3C43472D');
        $this->addSql('DROP TABLE app_boite');
        $this->addSql('DROP INDEX UNIQ_13960E2A3C43472D ON app_intervention');
        $this->addSql('ALTER TABLE app_intervention DROP boite_id');
    }
}
