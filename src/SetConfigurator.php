<?php

declare(strict_types=1);

namespace SetConfigurator;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class SetConfigurator extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);

        // Reihenfolge wegen Fremdschlüsseln: Kinder zuerst.
        foreach (['set_configurator_option_field', 'set_configurator_option', 'set_configurator_slot', 'set_configurator_product', 'set_configurator_target', 'set_configurator'] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }
}
