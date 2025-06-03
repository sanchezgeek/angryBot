<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250602160221 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_54DFAB558A90ABA9ECC836F9301935EB ON setting_value (key, symbol, position_side) NULLS NOT DISTINCT
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DROP INDEX UNIQ_54DFAB558A90ABA9ECC836F9301935EB
        SQL);
    }
}
