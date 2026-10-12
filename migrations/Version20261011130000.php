<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the notification provider on events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events ADD provider VARCHAR(16) DEFAULT \'bank\' NOT NULL');
        $this->addSql('ALTER TABLE events ALTER COLUMN provider DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events DROP provider');
    }
}
