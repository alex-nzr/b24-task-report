<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Report jobs, idempotent task rows and Messenger queue on PostgreSQL 15';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql("CREATE TABLE report_runs (id VARCHAR(32) PRIMARY KEY, employee_id INTEGER NOT NULL, employee_name TEXT NOT NULL, date_from DATE NOT NULL, date_to DATE NOT NULL, timezone VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'queued', cursor_id INTEGER NOT NULL DEFAULT 0, max_id INTEGER DEFAULT NULL, checked_tasks INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0, retry_at BIGINT DEFAULT NULL, error_code VARCHAR(128) DEFAULT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, started_at TIMESTAMPTZ DEFAULT NULL, finished_at TIMESTAMPTZ DEFAULT NULL)");
        $this->addSql('CREATE TABLE report_tasks (run_id VARCHAR(32) NOT NULL REFERENCES report_runs(id) ON DELETE CASCADE, task_id INTEGER NOT NULL, data JSONB NOT NULL, PRIMARY KEY(run_id, task_id))');
        $this->addSql('CREATE INDEX report_runs_created ON report_runs(created_at)');
        $this->addSql('CREATE TABLE messenger_messages (id BIGSERIAL PRIMARY KEY, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)');
        $this->addSql('CREATE INDEX messenger_queue ON messenger_messages(queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE report_tasks');
        $this->addSql('DROP TABLE report_runs');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
