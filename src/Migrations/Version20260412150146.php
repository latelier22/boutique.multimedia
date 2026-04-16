<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260412150146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rachat_items ADD boite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE rachat_items ADD CONSTRAINT FK_2117FC5C3C43472D FOREIGN KEY (boite_id) REFERENCES app_boite (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_2117FC5C3C43472D ON rachat_items (boite_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rachat_items DROP FOREIGN KEY FK_2117FC5C3C43472D');
        $this->addSql('DROP INDEX IDX_2117FC5C3C43472D ON rachat_items');
        $this->addSql('ALTER TABLE rachat_items DROP boite_id');
    }
}
