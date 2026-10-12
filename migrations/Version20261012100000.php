<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record every inbound notification with its response';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbound_requests ALTER COLUMN event_id DROP NOT NULL');
        $this->addSql('ALTER TABLE inbound_requests ADD response_status SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE inbound_requests ADD response_body TEXT DEFAULT NULL');
        $this->addSql('UPDATE inbound_requests SET response_status = 202, response_body = \'\'');
        $this->addSql('CREATE INDEX idx_inbound_requests_received_at ON inbound_requests (received_at)');
        $this->addSql('CREATE INDEX idx_inbound_requests_response_status ON inbound_requests (response_status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_inbound_requests_response_status');
        $this->addSql('DROP INDEX idx_inbound_requests_received_at');
        $this->addSql('DELETE FROM inbound_requests WHERE event_id IS NULL');
        $this->addSql('ALTER TABLE inbound_requests DROP response_body');
        $this->addSql('ALTER TABLE inbound_requests DROP response_status');
        $this->addSql('ALTER TABLE inbound_requests ALTER COLUMN event_id SET NOT NULL');
    }
}
