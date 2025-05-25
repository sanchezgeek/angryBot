<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250525102044 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE symbol_price_history_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE symbol_price_history (id INT NOT NULL, symbol VARCHAR(255) NOT NULL, last_price DOUBLE PRECISION NOT NULL, date_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN symbol_price_history.date_time IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F89DF6A2ECC836F94F4A11B1 ON symbol_price_history (symbol, date_time)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE symbol_price_history_id_seq CASCADE');
        $this->addSql('DROP TABLE symbol_price_history');
    }
}
