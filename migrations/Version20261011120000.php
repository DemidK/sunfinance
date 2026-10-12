<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow repeated event ids on intake, guard postings per event id and direction';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_events_event_id');
        $this->addSql('CREATE INDEX idx_events_event_id ON events (event_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_transactions_event_direction ON transactions (event_id, direction)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_transactions_event_direction');
        $this->addSql('DROP INDEX idx_events_event_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_events_event_id ON events (event_id)');
    }
}
