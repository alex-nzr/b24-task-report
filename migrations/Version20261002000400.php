<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scoped task selection denominator; invalidate active old selection and remove unused Messenger queue';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql('ALTER TABLE report_runs ADD total_tasks INTEGER DEFAULT NULL CHECK (total_tasks >= 0)');
        $this->addSql("UPDATE report_runs SET status = 'failed', error_code = 'SELECTION_CHANGED', retry_at = NULL, finished_at = CURRENT_TIMESTAMP WHERE status IN ('queued', 'running', 'waiting')");
        $this->addSql("UPDATE report_errors SET state = 'exhausted', retry_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE state = 'pending' AND run_id IN (SELECT id FROM report_runs WHERE error_code = 'SELECTION_CHANGED')");
        $this->addSql('DROP TABLE messenger_messages');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE report_runs DROP total_tasks');
        $this->addSql('CREATE TABLE messenger_messages (id BIGSERIAL PRIMARY KEY, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)');
        $this->addSql('CREATE INDEX messenger_queue ON messenger_messages(queue_name, available_at, delivered_at, id)');
    }
}
