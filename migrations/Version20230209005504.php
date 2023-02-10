<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20230209005504 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE buy_order_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE buy_order (id INT NOT NULL, price DOUBLE PRECISION NOT NULL, volume DOUBLE PRECISION NOT NULL, trigger_delta DOUBLE PRECISION DEFAULT NULL, position_side VARCHAR(255) NOT NULL, context JSONB NOT NULL, PRIMARY KEY(id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE buy_order_id_seq CASCADE');
        $this->addSql('DROP TABLE buy_order');
    }
}
