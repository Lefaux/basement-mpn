<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261026134501 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE scan_state (drive_id VARCHAR(128) NOT NULL, change_token LONGTEXT DEFAULT NULL, last_attempt_at DATETIME DEFAULT NULL, last_error LONGTEXT DEFAULT NULL, PRIMARY KEY (drive_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tracks (id INT AUTO_INCREMENT NOT NULL, drive_id VARCHAR(128) NOT NULL, parent_drive_id VARCHAR(128) DEFAULT NULL, artist VARCHAR(255) DEFAULT NULL, folder_name VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, path LONGTEXT NOT NULL, stage VARCHAR(40) NOT NULL, tags JSON NOT NULL, issues JSON NOT NULL, files JSON NOT NULL, clean_layout TINYINT NOT NULL, archived TINYINT NOT NULL, drive_modified_at DATETIME DEFAULT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_246D2A2E86E5E0C4 (drive_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE scan_state');
        $this->addSql('DROP TABLE tracks');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
