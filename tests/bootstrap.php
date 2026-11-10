<?php

declare(strict_types=1);

/*
 * Test-Start innerhalb eines Shopware-Shops (ohne eigenes composer install im Plugin).
 * Aufruf aus dem Shop-Hauptordner:
 *   vendor/bin/phpunit -c custom/plugins/SetConfigurator/phpunit.shop.xml
 */
$shopRoot = getenv('SHOP_ROOT') ?: dirname(__DIR__, 4);
require $shopRoot . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $map = [
        'SetConfigurator\\Tests\\' => __DIR__ . '/',
        'SetConfigurator\\' => dirname(__DIR__) . '/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
