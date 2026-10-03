<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist pending task page to avoid repeatedly loading it during continuations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE report_runs ADD pending_tasks JSONB NOT NULL DEFAULT '[]'::jsonb");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE report_runs DROP pending_tasks');
    }
}
