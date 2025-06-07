<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250603090955 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE symbol (name VARCHAR(50) NOT NULL, associated_coin VARCHAR(10) NOT NULL, associated_category VARCHAR(10) NOT NULL, min_order_qty DOUBLE PRECISION NOT NULL, min_notional_order_value DOUBLE PRECISION NOT NULL, price_precision INT NOT NULL, PRIMARY KEY(name))
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DROP TABLE symbol
        SQL);
    }
}
