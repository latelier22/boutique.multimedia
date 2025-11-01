<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251027123822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE rachats DROP photos_json, CHANGE piece_identite_url piece_identite_url LONGTEXT DEFAULT NULL, CHANGE adresse adresse LONGTEXT DEFAULT NULL, CHANGE signature_url signature_url LONGTEXT DEFAULT NULL, CHANGE pdf_url pdf_url LONGTEXT DEFAULT NULL, CHANGE photo1 photo1 LONGTEXT DEFAULT NULL, CHANGE photo2 photo2 LONGTEXT DEFAULT NULL, CHANGE photo3 photo3 LONGTEXT DEFAULT NULL, CHANGE colonne1 colonne1 LONGTEXT DEFAULT NULL, CHANGE colonne2 colonne2 LONGTEXT DEFAULT NULL, CHANGE colonne3 colonne3 LONGTEXT DEFAULT NULL, CHANGE colonne4 colonne4 LONGTEXT DEFAULT NULL, CHANGE created_at created_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE rachats ADD photos_json TEXT DEFAULT NULL, CHANGE piece_identite_url piece_identite_url TEXT DEFAULT NULL, CHANGE adresse adresse TEXT DEFAULT NULL, CHANGE signature_url signature_url TEXT DEFAULT NULL, CHANGE pdf_url pdf_url TEXT DEFAULT NULL, CHANGE photo1 photo1 TEXT DEFAULT NULL, CHANGE photo2 photo2 TEXT DEFAULT NULL, CHANGE photo3 photo3 TEXT DEFAULT NULL, CHANGE colonne1 colonne1 TEXT DEFAULT NULL, CHANGE colonne2 colonne2 TEXT DEFAULT NULL, CHANGE colonne3 colonne3 TEXT DEFAULT NULL, CHANGE colonne4 colonne4 TEXT DEFAULT NULL, CHANGE created_at created_at DATETIME DEFAULT NULL
        SQL);
    }
}
