<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260705161659 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reviews DROP FOREIGN KEY `FK_6970EB0F4584665A`');
        $this->addSql('ALTER TABLE reviews DROP FOREIGN KEY `FK_6970EB0FA76ED395`');
        $this->addSql('ALTER TABLE reviews ADD title VARCHAR(150) DEFAULT NULL, ADD body LONGTEXT NOT NULL, ADD status VARCHAR(20) NOT NULL, ADD rejection_reason VARCHAR(255) DEFAULT NULL, ADD purchased_variant VARCHAR(100) DEFAULT NULL, ADD helpful_votes INT DEFAULT 0 NOT NULL, ADD moderated_at DATETIME DEFAULT NULL, DROP comment');
        $this->addSql('ALTER TABLE reviews ADD CONSTRAINT FK_6970EB0F4584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reviews ADD CONSTRAINT FK_6970EB0FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reviews DROP FOREIGN KEY FK_6970EB0F4584665A');
        $this->addSql('ALTER TABLE reviews DROP FOREIGN KEY FK_6970EB0FA76ED395');
        $this->addSql('ALTER TABLE reviews ADD comment LONGTEXT DEFAULT NULL, DROP title, DROP body, DROP status, DROP rejection_reason, DROP purchased_variant, DROP helpful_votes, DROP moderated_at');
        $this->addSql('ALTER TABLE reviews ADD CONSTRAINT `FK_6970EB0F4584665A` FOREIGN KEY (product_id) REFERENCES products (id)');
        $this->addSql('ALTER TABLE reviews ADD CONSTRAINT `FK_6970EB0FA76ED395` FOREIGN KEY (user_id) REFERENCES users (id)');
    }
}
