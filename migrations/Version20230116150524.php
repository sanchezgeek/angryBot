<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20230116150524 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates delivery table.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE delivery_id_seq MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE delivery (id INT NOT NULL, order_id INT NOT NULL, address VARCHAR(255) NOT NULL, distance INT DEFAULT NULL, cost INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3781EC108D9F6D38 ON delivery (order_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE delivery_id_seq CASCADE');
        $this->addSql('DROP TABLE delivery');
    }
}
