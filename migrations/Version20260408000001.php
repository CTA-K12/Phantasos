<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create initial CTA Symfony Starter schema: app_user, app_setting, audit_log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(180) NOT NULL, google_id VARCHAR(255) DEFAULT NULL, display_name VARCHAR(255) NOT NULL, avatar_url VARCHAR(512) DEFAULT NULL, roles JSON NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E976F5C865 ON app_user (google_id)');
        $this->addSql('CREATE INDEX IDX_88BDF3E91B5771DD ON app_user (is_active)');

        $this->addSql('CREATE TABLE app_setting (key_name VARCHAR(100) NOT NULL, value VARCHAR(255) NOT NULL, PRIMARY KEY (key_name))');

        $this->addSql('CREATE TABLE audit_log (id UUID NOT NULL, action VARCHAR(100) NOT NULL, entity_type VARCHAR(100) DEFAULT NULL, entity_id VARCHAR(36) DEFAULT NULL, metadata JSON NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_audit_log_user ON audit_log (user_id)');
        $this->addSql('CREATE INDEX idx_audit_log_action ON audit_log (action)');
        $this->addSql('CREATE INDEX idx_audit_log_created_at ON audit_log (created_at)');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F5A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql("INSERT INTO app_setting (key_name, value) VALUES ('data_retention_months', '36')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT FK_F6E1C0F5A76ED395');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE app_setting');
        $this->addSql('DROP TABLE app_user');
    }
}
