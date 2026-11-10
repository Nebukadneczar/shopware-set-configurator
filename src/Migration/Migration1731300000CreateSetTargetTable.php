<?php

declare(strict_types=1);

namespace SetConfigurator\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Zuweisung eines Sets nach Kategorie oder Hersteller (zusätzlich zur Zuweisung an einzelne Produkte).
 */
class Migration1731300000CreateSetTargetTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1731300000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `set_configurator_target` (
    `id` BINARY(16) NOT NULL,
    `set_id` BINARY(16) NOT NULL,
    `type` VARCHAR(16) NOT NULL,
    `category_id` BINARY(16) NULL,
    `category_version_id` BINARY(16) NULL,
    `manufacturer_id` BINARY(16) NULL,
    `manufacturer_version_id` BINARY(16) NULL,
    `include_children` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    KEY `idx.set_configurator_target.set_id` (`set_id`),
    KEY `idx.set_configurator_target.category` (`category_id`),
    KEY `idx.set_configurator_target.manufacturer` (`manufacturer_id`),
    CONSTRAINT `fk.set_configurator_target.set_id` FOREIGN KEY (`set_id`)
        REFERENCES `set_configurator` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk.set_configurator_target.category` FOREIGN KEY (`category_id`, `category_version_id`)
        REFERENCES `category` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk.set_configurator_target.manufacturer` FOREIGN KEY (`manufacturer_id`, `manufacturer_version_id`)
        REFERENCES `product_manufacturer` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
