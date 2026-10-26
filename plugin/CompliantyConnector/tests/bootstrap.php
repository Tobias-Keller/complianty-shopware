<?php declare(strict_types=1);
use Composer\Autoload\ClassLoader;

// Unit-Tests brauchen keinen Kernel, nur den Autoloader des Shops (Shopware-Kern, Symfony) und die Plugin-Namespaces.
// Pfad: <shop>/custom/plugins/CompliantyConnector/tests
$shopRoot = \dirname(__DIR__, 4);
$autoload = $shopRoot . '/vendor/autoload.php';

if (!is_file($autoload)) {
    throw new RuntimeException('Shop-Autoloader nicht gefunden. Das Plugin muss unter custom/plugins/ liegen: ' . $autoload);
}

/** @var ClassLoader $loader */
$loader = require $autoload;
$loader->addPsr4('Complianty\\Connector\\', __DIR__ . '/../src');
$loader->addPsr4('Complianty\\Connector\\Tests\\', __DIR__);
