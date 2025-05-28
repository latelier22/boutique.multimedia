<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250526065033 extends AbstractMigration
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
            CREATE TABLE asdoria_configurator (id INT AUTO_INCREMENT NOT NULL, configurator_step_id INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, archived_at DATETIME DEFAULT NULL, enabled TINYINT(1) NOT NULL, position INT NOT NULL, configuration LONGTEXT NOT NULL COMMENT '(DC2Type:array)', calculator VARCHAR(255) NOT NULL, INDEX IDX_3D7B857665505800 (configurator_step_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE asdoria_configurator_channel (configurator_id INT NOT NULL, channelinterface_id INT NOT NULL, INDEX IDX_5EF4B34DDF663348 (configurator_id), INDEX IDX_5EF4B34DEC6CA45D (channelinterface_id), PRIMARY KEY(configurator_id, channelinterface_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE asdoria_configurator_product (configurator_id INT NOT NULL, productinterface_id INT NOT NULL, INDEX IDX_2F4739A7DF663348 (configurator_id), INDEX IDX_2F4739A75999C563 (productinterface_id), PRIMARY KEY(configurator_id, productinterface_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE asdoria_configurator_image (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, type VARCHAR(255) DEFAULT NULL, path VARCHAR(255) NOT NULL, INDEX IDX_7BF568A37E3C61F9 (owner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE asdoria_configurator_item (id INT AUTO_INCREMENT NOT NULL, configurator_id INT NOT NULL, configurator_step_id INT NOT NULL, product_attribute_id INT DEFAULT NULL, product_id INT DEFAULT NULL, code VARCHAR(255) NOT NULL, configuration JSON DEFAULT NULL COMMENT '(DC2Type:json)', position INT DEFAULT NULL, discr VARCHAR(255) NOT NULL, calculator VARCHAR(255) DEFAULT NULL, INDEX IDX_DC67C96CDF663348 (configurator_id), INDEX IDX_DC67C96C65505800 (configurator_step_id), INDEX IDX_DC67C96C3B420C91 (product_attribute_id), INDEX IDX_DC67C96C4584665A (product_id), UNIQUE INDEX UNIQ_DC67C96C77153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE asdoria_configurator_item_image (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, type VARCHAR(255) DEFAULT NULL, path VARCHAR(255) NOT NULL, INDEX IDX_F654AEA67E3C61F9 (owner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE asdoria_configurator_item_translation (id INT AUTO_INCREMENT NOT NULL, translatable_id INT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, locale VARCHAR(255) NOT NULL, INDEX IDX_DC6069C92C2AC5D3 (translatable_id), UNIQUE INDEX asdoria_configurator_item_translation_uniq_trans (translatable_id, locale), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE asdoria_configurator_step (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_80C5124E77153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE asdoria_configurator_step_image (id INT AUTO_INCREMENT NOT NULL, image_id INT DEFAULT NULL, type VARCHAR(255) DEFAULT NULL, path VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_C0D142893DA5256D (image_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE asdoria_configurator_step_translation (id INT AUTO_INCREMENT NOT NULL, translatable_id INT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, locale VARCHAR(255) NOT NULL, INDEX IDX_3DDF2BDF2C2AC5D3 (translatable_id), UNIQUE INDEX asdoria_configurator_step_translation_uniq_trans (translatable_id, locale), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE asdoria_configurator_translation (id INT AUTO_INCREMENT NOT NULL, translatable_id INT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, metaTitle VARCHAR(255) DEFAULT NULL, metaKeywords VARCHAR(1000) DEFAULT NULL, metaDescription VARCHAR(1000) DEFAULT NULL, metaRobots VARCHAR(255) DEFAULT NULL, metaCanonical VARCHAR(255) DEFAULT NULL, locale VARCHAR(255) NOT NULL, INDEX IDX_F6C213B22C2AC5D3 (translatable_id), UNIQUE INDEX slug_uidx (locale, slug), UNIQUE INDEX asdoria_configurator_translation_uniq_trans (translatable_id, locale), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE asdoria_order_item_attribute_value (id INT AUTO_INCREMENT NOT NULL, order_item_id INT DEFAULT NULL, attribute_id INT DEFAULT NULL, locale_code VARCHAR(255) DEFAULT NULL, text_value LONGTEXT DEFAULT NULL, boolean_value TINYINT(1) DEFAULT NULL, integer_value INT DEFAULT NULL, float_value DOUBLE PRECISION DEFAULT NULL, datetime_value DATETIME DEFAULT NULL, date_value DATE DEFAULT NULL, json_value JSON DEFAULT NULL COMMENT '(DC2Type:json)', INDEX IDX_951E1690E415FB15 (order_item_id), INDEX IDX_951E1690B6E62EFA (attribute_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator ADD CONSTRAINT FK_3D7B857665505800 FOREIGN KEY (configurator_step_id) REFERENCES asdoria_configurator_step (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_channel ADD CONSTRAINT FK_5EF4B34DDF663348 FOREIGN KEY (configurator_id) REFERENCES asdoria_configurator (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_channel ADD CONSTRAINT FK_5EF4B34DEC6CA45D FOREIGN KEY (channelinterface_id) REFERENCES sylius_channel (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_product ADD CONSTRAINT FK_2F4739A7DF663348 FOREIGN KEY (configurator_id) REFERENCES asdoria_configurator (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_product ADD CONSTRAINT FK_2F4739A75999C563 FOREIGN KEY (productinterface_id) REFERENCES sylius_product (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_image ADD CONSTRAINT FK_7BF568A37E3C61F9 FOREIGN KEY (owner_id) REFERENCES asdoria_configurator (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_item ADD CONSTRAINT FK_DC67C96CDF663348 FOREIGN KEY (configurator_id) REFERENCES asdoria_configurator (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_item ADD CONSTRAINT FK_DC67C96C65505800 FOREIGN KEY (configurator_step_id) REFERENCES asdoria_configurator_step (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_item ADD CONSTRAINT FK_DC67C96C3B420C91 FOREIGN KEY (product_attribute_id) REFERENCES sylius_product_attribute (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_item ADD CONSTRAINT FK_DC67C96C4584665A FOREIGN KEY (product_id) REFERENCES sylius_product (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_item_image ADD CONSTRAINT FK_F654AEA67E3C61F9 FOREIGN KEY (owner_id) REFERENCES asdoria_configurator_item (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_item_translation ADD CONSTRAINT FK_DC6069C92C2AC5D3 FOREIGN KEY (translatable_id) REFERENCES asdoria_configurator_item (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_step_image ADD CONSTRAINT FK_C0D142893DA5256D FOREIGN KEY (image_id) REFERENCES asdoria_configurator_step (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_step_translation ADD CONSTRAINT FK_3DDF2BDF2C2AC5D3 FOREIGN KEY (translatable_id) REFERENCES asdoria_configurator_step (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_translation ADD CONSTRAINT FK_F6C213B22C2AC5D3 FOREIGN KEY (translatable_id) REFERENCES asdoria_configurator (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_order_item_attribute_value ADD CONSTRAINT FK_951E1690E415FB15 FOREIGN KEY (order_item_id) REFERENCES sylius_order_item (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_order_item_attribute_value ADD CONSTRAINT FK_951E1690B6E62EFA FOREIGN KEY (attribute_id) REFERENCES sylius_product_attribute (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_adjustment CHANGE details details JSON NOT NULL COMMENT '(DC2Type:json)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_gateway_config CHANGE config config JSON NOT NULL COMMENT '(DC2Type:json)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_order_item ADD configurator_id INT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_order_item ADD CONSTRAINT FK_77B587EDDF663348 FOREIGN KEY (configurator_id) REFERENCES asdoria_configurator (id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_77B587EDDF663348 ON sylius_order_item (configurator_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_payment CHANGE details details JSON NOT NULL COMMENT '(DC2Type:json)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_product CHANGE vendor_id vendor_id INT NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_product ADD CONSTRAINT FK_677B9B74F603EE73 FOREIGN KEY (vendor_id) REFERENCES odiseo_vendor (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_product_attribute_value CHANGE json_value json_value JSON DEFAULT NULL COMMENT '(DC2Type:json)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE messenger_messages CHANGE created_at created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE available_at available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE delivered_at delivered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_order_item DROP FOREIGN KEY FK_77B587EDDF663348
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator DROP FOREIGN KEY FK_3D7B857665505800
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_channel DROP FOREIGN KEY FK_5EF4B34DDF663348
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_channel DROP FOREIGN KEY FK_5EF4B34DEC6CA45D
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_product DROP FOREIGN KEY FK_2F4739A7DF663348
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_product DROP FOREIGN KEY FK_2F4739A75999C563
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_image DROP FOREIGN KEY FK_7BF568A37E3C61F9
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_item DROP FOREIGN KEY FK_DC67C96CDF663348
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_item DROP FOREIGN KEY FK_DC67C96C65505800
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_item DROP FOREIGN KEY FK_DC67C96C3B420C91
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_item DROP FOREIGN KEY FK_DC67C96C4584665A
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_item_image DROP FOREIGN KEY FK_F654AEA67E3C61F9
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_item_translation DROP FOREIGN KEY FK_DC6069C92C2AC5D3
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_step_image DROP FOREIGN KEY FK_C0D142893DA5256D
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_step_translation DROP FOREIGN KEY FK_3DDF2BDF2C2AC5D3
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_configurator_translation DROP FOREIGN KEY FK_F6C213B22C2AC5D3
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_order_item_attribute_value DROP FOREIGN KEY FK_951E1690E415FB15
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE asdoria_order_item_attribute_value DROP FOREIGN KEY FK_951E1690B6E62EFA
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE Upload
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE asdoria_configurator
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE asdoria_configurator_channel
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE asdoria_configurator_product
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE asdoria_configurator_image
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE asdoria_configurator_item
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE asdoria_configurator_item_image
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE asdoria_configurator_item_translation
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE asdoria_configurator_step
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE asdoria_configurator_step_image
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE asdoria_configurator_step_translation
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE asdoria_configurator_translation
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE asdoria_order_item_attribute_value
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_product DROP FOREIGN KEY FK_677B9B74F603EE73
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_product CHANGE vendor_id vendor_id INT UNSIGNED DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_adjustment CHANGE details details JSON NOT NULL COMMENT '(DC2Type:json)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_gateway_config CHANGE config config JSON NOT NULL COMMENT '(DC2Type:json)'
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_77B587EDDF663348 ON sylius_order_item
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_order_item DROP configurator_id
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_product_attribute_value CHANGE json_value json_value JSON DEFAULT NULL COMMENT '(DC2Type:json)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sylius_payment CHANGE details details JSON NOT NULL COMMENT '(DC2Type:json)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE messenger_messages CHANGE created_at created_at DATETIME NOT NULL, CHANGE available_at available_at DATETIME NOT NULL, CHANGE delivered_at delivered_at DATETIME DEFAULT NULL
        SQL);
    }
}
