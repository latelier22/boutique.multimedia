<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251213165139 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE rachats ADD revendeur_id INT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE rachats ADD CONSTRAINT FK_CE792D91F4218D56 FOREIGN KEY (revendeur_id) REFERENCES revendeurs (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_CE792D91F4218D56 ON rachats (revendeur_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE rachats DROP FOREIGN KEY FK_CE792D91F4218D56
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_CE792D91F4218D56 ON rachats
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE rachats DROP revendeur_id
        SQL);
    }
}
