<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resumable command scheduling and per-stage report error history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE report_runs ADD last_processed_at TIMESTAMPTZ DEFAULT NULL');
        $this->addSql("CREATE TABLE report_errors (id BIGSERIAL PRIMARY KEY, run_id VARCHAR(32) NOT NULL REFERENCES report_runs(id) ON DELETE CASCADE, task_id INTEGER DEFAULT NULL, task_title TEXT DEFAULT NULL, stage VARCHAR(32) NOT NULL, error_code VARCHAR(128) NOT NULL, attempts INTEGER NOT NULL, state VARCHAR(20) NOT NULL, retry_at BIGINT DEFAULT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE NULLS NOT DISTINCT (run_id, task_id, stage))");
        $this->addSql('CREATE INDEX report_runs_ready ON report_runs(status, retry_at, last_processed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE report_errors');
        $this->addSql('DROP INDEX report_runs_ready');
        $this->addSql('ALTER TABLE report_runs DROP last_processed_at');
    }
}
