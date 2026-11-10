<?php

declare(strict_types=1);

namespace SetConfigurator\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1731200000CreateSetTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1731200000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `set_configurator` (
    `id` BINARY(16) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` LONGTEXT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `price_formula` VARCHAR(1000) NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `set_configurator_slot` (
    `id` BINARY(16) NOT NULL,
    `set_id` BINARY(16) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` LONGTEXT NULL,
    `template` VARCHAR(64) NOT NULL DEFAULT 'select',
    `min_select` INT(11) NOT NULL DEFAULT 0,
    `max_select` INT(11) NULL,
    `position` INT(11) NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    KEY `idx.set_configurator_slot.set_id` (`set_id`),
    CONSTRAINT `fk.set_configurator_slot.set_id` FOREIGN KEY (`set_id`)
        REFERENCES `set_configurator` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `set_configurator_option` (
    `id` BINARY(16) NOT NULL,
    `slot_id` BINARY(16) NOT NULL,
    `type` VARCHAR(16) NOT NULL DEFAULT 'product',
    `product_id` BINARY(16) NULL,
    `product_version_id` BINARY(16) NULL,
    `label` VARCHAR(255) NULL,
    `quantity_min` INT(11) NOT NULL DEFAULT 1,
    `quantity_max` INT(11) NOT NULL DEFAULT 1,
    `price_formula` VARCHAR(1000) NULL,
    `preselected` TINYINT(1) NOT NULL DEFAULT 0,
    `position` INT(11) NOT NULL DEFAULT 0,
    `nested_set_id` BINARY(16) NULL,
    `custom_values` JSON NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    KEY `idx.set_configurator_option.slot_id` (`slot_id`),
    KEY `idx.set_configurator_option.product` (`product_id`, `product_version_id`),
    CONSTRAINT `json.set_configurator_option.custom_values` CHECK (JSON_VALID(`custom_values`)),
    CONSTRAINT `fk.set_configurator_option.slot_id` FOREIGN KEY (`slot_id`)
        REFERENCES `set_configurator_slot` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk.set_configurator_option.product` FOREIGN KEY (`product_id`, `product_version_id`)
        REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk.set_configurator_option.nested_set_id` FOREIGN KEY (`nested_set_id`)
        REFERENCES `set_configurator` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `set_configurator_option_field` (
    `id` BINARY(16) NOT NULL,
    `option_id` BINARY(16) NOT NULL,
    `type` VARCHAR(16) NOT NULL DEFAULT 'text',
    `name` VARCHAR(64) NOT NULL,
    `label` VARCHAR(255) NOT NULL,
    `required` TINYINT(1) NOT NULL DEFAULT 0,
    `choices` JSON NULL,
    `price_formula` VARCHAR(1000) NULL,
    `position` INT(11) NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    KEY `idx.set_configurator_option_field.option_id` (`option_id`),
    CONSTRAINT `json.set_configurator_option_field.choices` CHECK (JSON_VALID(`choices`)),
    CONSTRAINT `fk.set_configurator_option_field.option_id` FOREIGN KEY (`option_id`)
        REFERENCES `set_configurator_option` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `set_configurator_product` (
    `id` BINARY(16) NOT NULL,
    `set_id` BINARY(16) NOT NULL,
    `product_id` BINARY(16) NOT NULL,
    `product_version_id` BINARY(16) NOT NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq.set_configurator_product.product` (`product_id`, `product_version_id`),
    KEY `idx.set_configurator_product.set_id` (`set_id`),
    CONSTRAINT `fk.set_configurator_product.set_id` FOREIGN KEY (`set_id`)
        REFERENCES `set_configurator` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk.set_configurator_product.product` FOREIGN KEY (`product_id`, `product_version_id`)
        REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
