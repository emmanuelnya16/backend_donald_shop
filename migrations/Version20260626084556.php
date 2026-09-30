<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260626084556 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE admins (id INT AUTO_INCREMENT NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, role VARCHAR(50) NOT NULL, is_active TINYINT DEFAULT 0 NOT NULL, must_change_password TINYINT DEFAULT 1 NOT NULL, activation_token VARCHAR(100) DEFAULT NULL, activation_token_expires_at DATETIME DEFAULT NULL, last_login_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_A2E0150FE7927C74 (email), UNIQUE INDEX UNIQ_A2E0150FB1B4826B (activation_token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE categories (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, name_en VARCHAR(150) DEFAULT NULL, slug VARCHAR(160) NOT NULL, image VARCHAR(255) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, parent_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_3AF34668989D9B62 (slug), INDEX IDX_3AF34668727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE delivery_addresses (id INT AUTO_INCREMENT NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, phone VARCHAR(20) NOT NULL, city VARCHAR(100) NOT NULL, district VARCHAR(150) NOT NULL, street VARCHAR(255) DEFAULT NULL, instructions LONGTEXT DEFAULT NULL, order_id INT NOT NULL, UNIQUE INDEX UNIQ_2BAF39848D9F6D38 (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE order_items (id INT AUTO_INCREMENT NOT NULL, product_name VARCHAR(200) NOT NULL, variant_label VARCHAR(100) DEFAULT NULL, product_image_url VARCHAR(500) DEFAULT NULL, quantity INT NOT NULL, unit_price INT NOT NULL, subtotal INT NOT NULL, order_id INT NOT NULL, product_variant_id INT DEFAULT NULL, INDEX IDX_62809DB08D9F6D38 (order_id), INDEX IDX_62809DB0A80EF684 (product_variant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE orders (id INT AUTO_INCREMENT NOT NULL, order_number VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, payment_method VARCHAR(20) NOT NULL, items_total INT NOT NULL, delivery_fee INT DEFAULT 0 NOT NULL, payment_fee INT DEFAULT 0 NOT NULL, discount_amount INT DEFAULT 0 NOT NULL, total_amount INT NOT NULL, promo_code_used VARCHAR(50) DEFAULT NULL, admin_notes LONGTEXT DEFAULT NULL, payment_poll_attempts INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, user_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_E52FFDEE551F0F81 (order_number), INDEX IDX_E52FFDEEA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payments (id INT AUTO_INCREMENT NOT NULL, provider VARCHAR(30) NOT NULL, amount INT NOT NULL, status VARCHAR(20) NOT NULL, payer_phone VARCHAR(20) DEFAULT NULL, deposit_id VARCHAR(100) DEFAULT NULL, provider_response JSON DEFAULT NULL, failure_code VARCHAR(100) DEFAULT NULL, failure_message VARCHAR(255) DEFAULT NULL, status_check_count INT DEFAULT 0 NOT NULL, last_checked_at DATETIME DEFAULT NULL, initiated_at DATETIME NOT NULL, confirmed_at DATETIME DEFAULT NULL, failed_at DATETIME DEFAULT NULL, order_id INT NOT NULL, UNIQUE INDEX UNIQ_65D29B329815E4B1 (deposit_id), INDEX IDX_65D29B328D9F6D38 (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_images (id INT AUTO_INCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, url VARCHAR(500) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, is_main TINYINT DEFAULT 0 NOT NULL, color VARCHAR(50) DEFAULT NULL, product_id INT NOT NULL, INDEX IDX_8263FFCE4584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_variants (id INT AUTO_INCREMENT NOT NULL, size VARCHAR(20) DEFAULT NULL, color VARCHAR(50) DEFAULT NULL, color_hex VARCHAR(10) DEFAULT NULL, stock INT DEFAULT 0 NOT NULL, alert_threshold INT DEFAULT 5 NOT NULL, extra_price INT DEFAULT 0 NOT NULL, sku VARCHAR(100) DEFAULT NULL, is_active TINYINT DEFAULT 1 NOT NULL, product_id INT NOT NULL, UNIQUE INDEX UNIQ_78283976F9038C4 (sku), INDEX IDX_782839764584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE products (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(200) NOT NULL, slug VARCHAR(220) NOT NULL, short_description VARCHAR(400) DEFAULT NULL, long_description LONGTEXT DEFAULT NULL, base_price INT NOT NULL, promo_price INT DEFAULT NULL, promo_starts_at DATETIME DEFAULT NULL, promo_ends_at DATETIME DEFAULT NULL, status VARCHAR(20) DEFAULT \'draft\' NOT NULL, meta_title VARCHAR(70) DEFAULT NULL, meta_description VARCHAR(170) DEFAULT NULL, sales_count INT DEFAULT 0 NOT NULL, average_rating DOUBLE PRECISION DEFAULT NULL, review_count INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, category_id INT NOT NULL, UNIQUE INDEX UNIQ_B3BA5A5A989D9B62 (slug), INDEX IDX_B3BA5A5A12469DE2 (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE promo_codes (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) NOT NULL, type VARCHAR(10) NOT NULL, value INT NOT NULL, min_order_amount INT DEFAULT 0 NOT NULL, max_uses INT DEFAULT NULL, used_count INT DEFAULT 0 NOT NULL, once_per_user TINYINT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, starts_at DATETIME DEFAULT NULL, ends_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_C84FDDB77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE refresh_tokens (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(128) NOT NULL, user_type VARCHAR(10) NOT NULL, user_id INT NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, created_from_ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, is_used TINYINT DEFAULT 0 NOT NULL, is_revoked TINYINT DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_9BACE7E1B3BC57DA (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reviews (id INT AUTO_INCREMENT NOT NULL, rating INT NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, product_id INT NOT NULL, INDEX IDX_6970EB0FA76ED395 (user_id), INDEX IDX_6970EB0F4584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, phone VARCHAR(20) NOT NULL, city VARCHAR(100) DEFAULT NULL, password VARCHAR(255) NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_1483A5E9444F97DD (phone), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE categories ADD CONSTRAINT FK_3AF34668727ACA70 FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE delivery_addresses ADD CONSTRAINT FK_2BAF39848D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE order_items ADD CONSTRAINT FK_62809DB08D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE order_items ADD CONSTRAINT FK_62809DB0A80EF684 FOREIGN KEY (product_variant_id) REFERENCES product_variants (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_E52FFDEEA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT FK_65D29B328D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_images ADD CONSTRAINT FK_8263FFCE4584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_variants ADD CONSTRAINT FK_782839764584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5A12469DE2 FOREIGN KEY (category_id) REFERENCES categories (id)');
        $this->addSql('ALTER TABLE reviews ADD CONSTRAINT FK_6970EB0FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE reviews ADD CONSTRAINT FK_6970EB0F4584665A FOREIGN KEY (product_id) REFERENCES products (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE categories DROP FOREIGN KEY FK_3AF34668727ACA70');
        $this->addSql('ALTER TABLE delivery_addresses DROP FOREIGN KEY FK_2BAF39848D9F6D38');
        $this->addSql('ALTER TABLE order_items DROP FOREIGN KEY FK_62809DB08D9F6D38');
        $this->addSql('ALTER TABLE order_items DROP FOREIGN KEY FK_62809DB0A80EF684');
        $this->addSql('ALTER TABLE orders DROP FOREIGN KEY FK_E52FFDEEA76ED395');
        $this->addSql('ALTER TABLE payments DROP FOREIGN KEY FK_65D29B328D9F6D38');
        $this->addSql('ALTER TABLE product_images DROP FOREIGN KEY FK_8263FFCE4584665A');
        $this->addSql('ALTER TABLE product_variants DROP FOREIGN KEY FK_782839764584665A');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY FK_B3BA5A5A12469DE2');
        $this->addSql('ALTER TABLE reviews DROP FOREIGN KEY FK_6970EB0FA76ED395');
        $this->addSql('ALTER TABLE reviews DROP FOREIGN KEY FK_6970EB0F4584665A');
        $this->addSql('DROP TABLE admins');
        $this->addSql('DROP TABLE categories');
        $this->addSql('DROP TABLE delivery_addresses');
        $this->addSql('DROP TABLE order_items');
        $this->addSql('DROP TABLE orders');
        $this->addSql('DROP TABLE payments');
        $this->addSql('DROP TABLE product_images');
        $this->addSql('DROP TABLE product_variants');
        $this->addSql('DROP TABLE products');
        $this->addSql('DROP TABLE promo_codes');
        $this->addSql('DROP TABLE refresh_tokens');
        $this->addSql('DROP TABLE reviews');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
