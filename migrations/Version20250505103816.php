<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250505103816 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE setting_value_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE setting_value (id INT NOT NULL, key VARCHAR(255) NOT NULL, symbol VARCHAR(255) DEFAULT NULL, position_side VARCHAR(255) DEFAULT NULL, value JSON DEFAULT NULL, PRIMARY KEY(id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE setting_value_id_seq CASCADE');
        $this->addSql('DROP TABLE setting_value');
    }
}
