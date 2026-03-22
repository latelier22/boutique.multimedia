<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260319091116 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sylius_invoicing_plugin_invoice DROP FOREIGN KEY FK_3AA279BF72F5A1AA');
        $this->addSql('ALTER TABLE sylius_invoicing_plugin_invoice DROP FOREIGN KEY FK_3AA279BF8D9F6D38');
        $this->addSql('ALTER TABLE sylius_invoicing_plugin_invoice DROP FOREIGN KEY FK_3AA279BF5CDB2AEB');
        $this->addSql('ALTER TABLE sylius_invoicing_plugin_invoice DROP FOREIGN KEY FK_3AA279BFCFE4AA36');
        $this->addSql('ALTER TABLE sylius_paypal_plugin_pay_pal_credentials DROP FOREIGN KEY FK_C56F54AD5AA1164F');
        $this->addSql('ALTER TABLE odiseo_banners_taxons DROP FOREIGN KEY FK_FB5C1345684EC833');
        $this->addSql('ALTER TABLE odiseo_banners_taxons DROP FOREIGN KEY FK_FB5C1345DE13F470');
        $this->addSql('ALTER TABLE odiseo_banner_translation DROP FOREIGN KEY FK_BC61EAF72C2AC5D3');
        $this->addSql('ALTER TABLE bitbag_wishlist_product DROP FOREIGN KEY FK_3DBE67A0FB8E54CD');
        $this->addSql('ALTER TABLE bitbag_wishlist_product DROP FOREIGN KEY FK_3DBE67A03B69A9AF');
        $this->addSql('ALTER TABLE bitbag_wishlist_product DROP FOREIGN KEY FK_3DBE67A04584665A');
        $this->addSql('ALTER TABLE sylius_invoicing_plugin_line_item DROP FOREIGN KEY FK_C91408292989F1FD');
        $this->addSql('ALTER TABLE sylius_invoicing_plugin_tax_item DROP FOREIGN KEY FK_2951C61C2989F1FD');
        $this->addSql('ALTER TABLE bitbag_wishlist DROP FOREIGN KEY FK_578D4E77A45D93BF');
        $this->addSql('ALTER TABLE bitbag_wishlist DROP FOREIGN KEY FK_578D4E7772F5A1AA');
        $this->addSql('ALTER TABLE odiseo_banners_channels DROP FOREIGN KEY FK_AAD9CFD0684EC833');
        $this->addSql('ALTER TABLE odiseo_banners_channels DROP FOREIGN KEY FK_AAD9CFD072F5A1AA');
        $this->addSql('DROP TABLE sylius_invoicing_plugin_invoice');
        $this->addSql('DROP TABLE sylius_paypal_plugin_pay_pal_credentials');
        $this->addSql('DROP TABLE odiseo_banner');
        $this->addSql('DROP TABLE sylius_invoicing_plugin_sequence');
        $this->addSql('DROP TABLE odiseo_banners_taxons');
        $this->addSql('DROP TABLE odiseo_banner_translation');
        $this->addSql('DROP TABLE bitbag_wishlist_product');
        $this->addSql('DROP TABLE sylius_invoicing_plugin_billing_data');
        $this->addSql('DROP TABLE sylius_invoicing_plugin_line_item');
        $this->addSql('DROP TABLE sylius_invoicing_plugin_shop_billing_data');
        $this->addSql('DROP TABLE sylius_invoicing_plugin_tax_item');
        $this->addSql('DROP TABLE bitbag_wishlist');
        $this->addSql('DROP TABLE odiseo_banners_channels');
        $this->addSql('ALTER TABLE rachats ADD attributes JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sylius_invoicing_plugin_invoice (id VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, billing_data_id INT DEFAULT NULL, shop_billing_data_id INT DEFAULT NULL, channel_id INT DEFAULT NULL, order_id INT DEFAULT NULL, issued_at DATETIME NOT NULL, currency_code VARCHAR(3) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, total INT NOT NULL, number VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, locale_code VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, payment_state VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, INDEX IDX_3AA279BF8D9F6D38 (order_id), UNIQUE INDEX UNIQ_3AA279BF5CDB2AEB (billing_data_id), UNIQUE INDEX UNIQ_3AA279BFB5282EDF (shop_billing_data_id), INDEX IDX_3AA279BF72F5A1AA (channel_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE sylius_paypal_plugin_pay_pal_credentials (id VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, payment_method_id INT DEFAULT NULL, access_token VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, creation_time DATETIME NOT NULL, expiration_time DATETIME NOT NULL, INDEX IDX_C56F54AD5AA1164F (payment_method_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE odiseo_banner (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_2FB97E5E77153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE sylius_invoicing_plugin_sequence (id INT AUTO_INCREMENT NOT NULL, idx INT NOT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE odiseo_banners_taxons (banner_id INT NOT NULL, taxon_id INT NOT NULL, INDEX IDX_FB5C1345DE13F470 (taxon_id), INDEX IDX_FB5C1345684EC833 (banner_id), PRIMARY KEY(banner_id, taxon_id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE odiseo_banner_translation (id INT AUTO_INCREMENT NOT NULL, translatable_id INT NOT NULL, image_name VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, mobile_image_name VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, url VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, main_text VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, secondary_text VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, button_text VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, locale VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, UNIQUE INDEX odiseo_banner_translation_uniq_trans (translatable_id, locale), INDEX IDX_BC61EAF72C2AC5D3 (translatable_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE bitbag_wishlist_product (id INT AUTO_INCREMENT NOT NULL, wishlist_id INT NOT NULL, product_id INT DEFAULT NULL, variant_id INT DEFAULT NULL, INDEX IDX_3DBE67A03B69A9AF (variant_id), INDEX IDX_3DBE67A0FB8E54CD (wishlist_id), INDEX IDX_3DBE67A04584665A (product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE sylius_invoicing_plugin_billing_data (id INT AUTO_INCREMENT NOT NULL, first_name VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, last_name VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, company VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, street VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, city VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, postcode VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, country_code VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, province_code VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, province_name VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE sylius_invoicing_plugin_line_item (id INT AUTO_INCREMENT NOT NULL, invoice_id VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, name VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, quantity INT NOT NULL, unit_price INT NOT NULL, subtotal INT NOT NULL, tax_total INT NOT NULL, total INT NOT NULL, variant_code VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, variant_name VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, tax_rate VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, discounted_unit_net_price INT DEFAULT NULL, INDEX IDX_C91408292989F1FD (invoice_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE sylius_invoicing_plugin_shop_billing_data (id INT AUTO_INCREMENT NOT NULL, company VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, tax_id VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, street VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, city VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, postcode VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, country_code VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, representative VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE sylius_invoicing_plugin_tax_item (id INT AUTO_INCREMENT NOT NULL, invoice_id VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, label VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, amount INT NOT NULL, INDEX IDX_2951C61C2989F1FD (invoice_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE bitbag_wishlist (id INT AUTO_INCREMENT NOT NULL, shop_user_id INT DEFAULT NULL, channel_id INT DEFAULT NULL, token VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_unicode_ci`, name VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_unicode_ci`, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX channel_shop_user_token_idx (channel_id, shop_user_id, token), INDEX IDX_578D4E7772F5A1AA (channel_id), INDEX IDX_578D4E77A45D93BF (shop_user_id), INDEX token_idx (token), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE odiseo_banners_channels (banner_id INT NOT NULL, channel_id INT NOT NULL, INDEX IDX_AAD9CFD072F5A1AA (channel_id), INDEX IDX_AAD9CFD0684EC833 (banner_id), PRIMARY KEY(banner_id, channel_id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE sylius_invoicing_plugin_invoice ADD CONSTRAINT FK_3AA279BF72F5A1AA FOREIGN KEY (channel_id) REFERENCES sylius_channel (id)');
        $this->addSql('ALTER TABLE sylius_invoicing_plugin_invoice ADD CONSTRAINT FK_3AA279BF8D9F6D38 FOREIGN KEY (order_id) REFERENCES sylius_order (id)');
        $this->addSql('ALTER TABLE sylius_invoicing_plugin_invoice ADD CONSTRAINT FK_3AA279BF5CDB2AEB FOREIGN KEY (billing_data_id) REFERENCES sylius_invoicing_plugin_billing_data (id)');
        $this->addSql('ALTER TABLE sylius_invoicing_plugin_invoice ADD CONSTRAINT FK_3AA279BFCFE4AA36 FOREIGN KEY (shop_billing_data_id) REFERENCES sylius_invoicing_plugin_shop_billing_data (id)');
        $this->addSql('ALTER TABLE sylius_paypal_plugin_pay_pal_credentials ADD CONSTRAINT FK_C56F54AD5AA1164F FOREIGN KEY (payment_method_id) REFERENCES sylius_payment_method (id)');
        $this->addSql('ALTER TABLE odiseo_banners_taxons ADD CONSTRAINT FK_FB5C1345684EC833 FOREIGN KEY (banner_id) REFERENCES odiseo_banner (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE odiseo_banners_taxons ADD CONSTRAINT FK_FB5C1345DE13F470 FOREIGN KEY (taxon_id) REFERENCES sylius_taxon (id)');
        $this->addSql('ALTER TABLE odiseo_banner_translation ADD CONSTRAINT FK_BC61EAF72C2AC5D3 FOREIGN KEY (translatable_id) REFERENCES odiseo_banner (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bitbag_wishlist_product ADD CONSTRAINT FK_3DBE67A0FB8E54CD FOREIGN KEY (wishlist_id) REFERENCES bitbag_wishlist (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bitbag_wishlist_product ADD CONSTRAINT FK_3DBE67A03B69A9AF FOREIGN KEY (variant_id) REFERENCES sylius_product_variant (id)');
        $this->addSql('ALTER TABLE bitbag_wishlist_product ADD CONSTRAINT FK_3DBE67A04584665A FOREIGN KEY (product_id) REFERENCES sylius_product (id)');
        $this->addSql('ALTER TABLE sylius_invoicing_plugin_line_item ADD CONSTRAINT FK_C91408292989F1FD FOREIGN KEY (invoice_id) REFERENCES sylius_invoicing_plugin_invoice (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sylius_invoicing_plugin_tax_item ADD CONSTRAINT FK_2951C61C2989F1FD FOREIGN KEY (invoice_id) REFERENCES sylius_invoicing_plugin_invoice (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bitbag_wishlist ADD CONSTRAINT FK_578D4E77A45D93BF FOREIGN KEY (shop_user_id) REFERENCES sylius_shop_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bitbag_wishlist ADD CONSTRAINT FK_578D4E7772F5A1AA FOREIGN KEY (channel_id) REFERENCES sylius_channel (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE odiseo_banners_channels ADD CONSTRAINT FK_AAD9CFD0684EC833 FOREIGN KEY (banner_id) REFERENCES odiseo_banner (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE odiseo_banners_channels ADD CONSTRAINT FK_AAD9CFD072F5A1AA FOREIGN KEY (channel_id) REFERENCES sylius_channel (id)');
        $this->addSql('ALTER TABLE rachats DROP attributes');
    }
}
