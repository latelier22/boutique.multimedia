<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251102182208 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE app_cash (id INT AUTO_INCREMENT NOT NULL, enabled TINYINT(1) DEFAULT 1 NOT NULL, amount NUMERIC(12, 2) NOT NULL, method VARCHAR(32) NOT NULL, paidAt DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', note LONGTEXT DEFAULT NULL, createdAt DATETIME DEFAULT NULL, updatedAt DATETIME DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE app_till (id INT AUTO_INCREMENT NOT NULL, dateTill VARCHAR(255) DEFAULT NULL, withdrawal VARCHAR(255) DEFAULT NULL, deposit VARCHAR(255) DEFAULT NULL, comments VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            DROP TABLE app_cash
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE app_till
        SQL);
    }
}
