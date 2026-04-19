<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260418133628 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hib_mobile_import_row ADD resolvedName VARCHAR(255) DEFAULT NULL, ADD resolvedBarcode VARCHAR(255) DEFAULT NULL, ADD resolvedProductsRefExt VARCHAR(255) DEFAULT NULL, ADD resolvedVat VARCHAR(50) DEFAULT NULL, ADD resolvedAccountingAccount VARCHAR(50) DEFAULT NULL, ADD resolvedBuyPrice DOUBLE PRECISION DEFAULT NULL, ADD resolvedSellPrice DOUBLE PRECISION DEFAULT NULL, ADD resolvedCategoryId INT DEFAULT NULL, ADD resolvedCategoryLabel VARCHAR(255) DEFAULT NULL, ADD resolvedSupplierId INT DEFAULT NULL, ADD matchType VARCHAR(50) DEFAULT NULL, ADD matchedProductId INT DEFAULT NULL, ADD isIgnored TINYINT(1) NOT NULL, ADD isBlocked TINYINT(1) NOT NULL, ADD blockReason VARCHAR(255) DEFAULT NULL, ADD isReady TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hib_mobile_import_row DROP resolvedName, DROP resolvedBarcode, DROP resolvedProductsRefExt, DROP resolvedVat, DROP resolvedAccountingAccount, DROP resolvedBuyPrice, DROP resolvedSellPrice, DROP resolvedCategoryId, DROP resolvedCategoryLabel, DROP resolvedSupplierId, DROP matchType, DROP matchedProductId, DROP isIgnored, DROP isBlocked, DROP blockReason, DROP isReady');
    }
}
