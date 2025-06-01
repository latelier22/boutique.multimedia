<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250531052039 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE Upload (id INT AUTO_INCREMENT NOT NULL, file VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE mbiz_settings_setting (id INT AUTO_INCREMENT NOT NULL, channel_id INT DEFAULT NULL, vendor VARCHAR(255) NOT NULL, plugin VARCHAR(255) NOT NULL, path VARCHAR(255) NOT NULL, locale_code VARCHAR(5) DEFAULT NULL, storage_type VARCHAR(10) NOT NULL, text_value TEXT DEFAULT NULL, boolean_value TINYINT(1) DEFAULT NULL, integer_value INT DEFAULT NULL, float_value DOUBLE PRECISION DEFAULT NULL, datetime_value DATETIME DEFAULT NULL, date_value DATE DEFAULT NULL, json_value JSON DEFAULT NULL COMMENT '(DC2Type:json)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL, INDEX IDX_404A67E772F5A1AA (channel_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE mbiz_settings_setting ADD CONSTRAINT FK_404A67E772F5A1AA FOREIGN KEY (channel_id) REFERENCES sylius_channel (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE odiseo_vendor_translation DROP FOREIGN KEY FK_5F5AE1AB2C2AC5D3
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE odiseo_vendor_channels DROP FOREIGN KEY FK_42A3C6D272F5A1AA
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE odiseo_vendor_channels DROP FOREIGN KEY FK_42A3C6D2F603EE73
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE odiseo_vendor_email DROP FOREIGN KEY FK_F58E945BF603EE73
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE odiseo_vendor
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE odiseo_vendor_translation
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE odiseo_vendor_channels
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE odiseo_vendor_email
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_677B9B74F603EE73 ON sylius_product
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_product DROP vendor_id
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_product_attribute ADD searchable TINYINT(1) DEFAULT 0 NOT NULL, ADD filterable TINYINT(1) DEFAULT 0 NOT NULL, ADD search_weight SMALLINT UNSIGNED DEFAULT 1 NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_product_option ADD searchable TINYINT(1) DEFAULT 0 NOT NULL, ADD filterable TINYINT(1) DEFAULT 0 NOT NULL, ADD search_weight SMALLINT UNSIGNED DEFAULT 1 NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE messenger_messages CHANGE created_at created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE available_at available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE delivered_at delivered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE odiseo_vendor (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, slug VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, email VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, logo_name VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, enabled TINYINT(1) NOT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_B506F54FE7927C74 (email), UNIQUE INDEX UNIQ_B506F54F5E237E06 (name), UNIQUE INDEX UNIQ_B506F54F989D9B62 (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = '' 
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE odiseo_vendor_translation (id INT AUTO_INCREMENT NOT NULL, translatable_id INT NOT NULL, description LONGTEXT CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, locale VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, INDEX IDX_5F5AE1AB2C2AC5D3 (translatable_id), UNIQUE INDEX odiseo_vendor_translation_uniq_trans (translatable_id, locale), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = '' 
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE odiseo_vendor_channels (channel_id INT NOT NULL, vendor_id INT NOT NULL, INDEX IDX_42A3C6D2F603EE73 (vendor_id), INDEX IDX_42A3C6D272F5A1AA (channel_id), PRIMARY KEY(channel_id, vendor_id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = '' 
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE odiseo_vendor_email (id INT AUTO_INCREMENT NOT NULL, vendor_id INT NOT NULL, value VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_F58E945BF603EE73 (vendor_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = '' 
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE odiseo_vendor_translation ADD CONSTRAINT FK_5F5AE1AB2C2AC5D3 FOREIGN KEY (translatable_id) REFERENCES odiseo_vendor (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE odiseo_vendor_channels ADD CONSTRAINT FK_42A3C6D272F5A1AA FOREIGN KEY (channel_id) REFERENCES sylius_channel (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE odiseo_vendor_channels ADD CONSTRAINT FK_42A3C6D2F603EE73 FOREIGN KEY (vendor_id) REFERENCES odiseo_vendor (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE odiseo_vendor_email ADD CONSTRAINT FK_F58E945BF603EE73 FOREIGN KEY (vendor_id) REFERENCES odiseo_vendor (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE mbiz_settings_setting DROP FOREIGN KEY FK_404A67E772F5A1AA
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE Upload
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE mbiz_settings_setting
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_product ADD vendor_id INT UNSIGNED DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_677B9B74F603EE73 ON sylius_product (vendor_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_product_option DROP searchable, DROP filterable, DROP search_weight
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_product_attribute DROP searchable, DROP filterable, DROP search_weight
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE messenger_messages CHANGE created_at created_at DATETIME NOT NULL, CHANGE available_at available_at DATETIME NOT NULL, CHANGE delivered_at delivered_at DATETIME DEFAULT NULL
        SQL);
    }
}
