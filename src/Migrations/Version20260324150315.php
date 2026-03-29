<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260324150315 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE hib_label_board (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, createdAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updatedAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', archived TINYINT(1) DEFAULT 0 NOT NULL, pdfFilename VARCHAR(255) DEFAULT NULL, pdfPath VARCHAR(500) DEFAULT NULL, pdfUrl VARCHAR(500) DEFAULT NULL, pdfGeneratedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE hib_label_board_item (id INT AUTO_INCREMENT NOT NULL, board_id INT NOT NULL, slot_index INT NOT NULL, productId INT NOT NULL, INDEX IDX_9D9F28E1E7EC5785 (board_id), UNIQUE INDEX uniq_label_board_slot (board_id, slot_index), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE hib_label_board_item ADD CONSTRAINT FK_9D9F28E1E7EC5785 FOREIGN KEY (board_id) REFERENCES hib_label_board (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hib_label_board_item DROP FOREIGN KEY FK_9D9F28E1E7EC5785');
        $this->addSql('DROP TABLE hib_label_board');
        $this->addSql('DROP TABLE hib_label_board_item');
    }
}
