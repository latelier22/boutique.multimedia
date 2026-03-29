<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260325170933 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rachats ADD boite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE rachats ADD CONSTRAINT FK_CE792D913C43472D FOREIGN KEY (boite_id) REFERENCES app_boite (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CE792D913C43472D ON rachats (boite_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rachats DROP FOREIGN KEY FK_CE792D913C43472D');
        $this->addSql('DROP INDEX UNIQ_CE792D913C43472D ON rachats');
        $this->addSql('ALTER TABLE rachats DROP boite_id');
    }
}
