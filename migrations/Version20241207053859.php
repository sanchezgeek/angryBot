<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20241207053859 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE buy_order ADD symbol VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE stop ADD symbol VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE buy_order DROP symbol');
        $this->addSql('ALTER TABLE stop DROP symbol');
    }
}
