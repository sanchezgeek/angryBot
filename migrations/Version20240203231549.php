<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240203231549 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Removes buy_order.trigger_delta field.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE buy_order DROP trigger_delta');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE buy_order ADD trigger_delta DOUBLE PRECISION DEFAULT NULL');
    }
}
