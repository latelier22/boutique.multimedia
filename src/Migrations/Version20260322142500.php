<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260322142500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_intervention (id INT AUTO_INCREMENT NOT NULL, intervention_number INT NOT NULL, status VARCHAR(30) NOT NULL, customerLastName VARCHAR(180) NOT NULL, customerFirstName VARCHAR(180) NOT NULL, customerPhone VARCHAR(50) DEFAULT NULL, deviceLabel VARCHAR(255) DEFAULT NULL, workToDo LONGTEXT DEFAULT NULL, hiboutikCustomerId INT DEFAULT NULL, unlockPayloadEncrypted LONGTEXT DEFAULT NULL, unlockSummary VARCHAR(255) DEFAULT NULL, tabletToken VARCHAR(64) DEFAULT NULL, tabletTokenExpiresAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', createdAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updatedAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_intervention_number (intervention_number), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE app_intervention');
    }
}
