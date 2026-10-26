<?php declare(strict_types=1);

use Complianty\Connector\Tests\Support\FakeComplianty;
use Composer\Autoload\ClassLoader;
use Shopware\Core\TestBootstrapper;

// Integrationstests laufen gegen einen echten Shopware-Kernel und eine eigene Datenbank.
// Der TestBootstrapper hängt "_test" an den Datenbanknamen (shopware -> shopware_test), die Entwicklungsdatenbank
// bleibt unberührt. Beim ersten Lauf installiert er Shopware dort (system:install), danach nur noch das Plugin.
// Nicht setzen: TEST_TOKEN=none, das würde die Entwicklungsdatenbank benutzen.
$shopRoot = \dirname(__DIR__, 4);
$autoload = $shopRoot . '/vendor/autoload.php';

if (!is_file($autoload)) {
    throw new RuntimeException('Shop-Autoloader nicht gefunden. Das Plugin muss unter custom/plugins/ liegen: ' . $autoload);
}

if (getenv('TEST_TOKEN') === 'none') {
    throw new RuntimeException('TEST_TOKEN=none würde die Entwicklungsdatenbank überschreiben. Abbruch.');
}

/** @var ClassLoader $loader */
$loader = require $autoload;
$loader->addPsr4('Complianty\\Connector\\Tests\\', __DIR__);

// Die Fake-API muss laufen, bevor der Kernel bootet: Der Plugin-Client liest die Basis-URL beim Kompilieren des Containers.
$fake = FakeComplianty::start();
$_SERVER['COMPLIANTY_API_BASE'] = $_ENV['COMPLIANTY_API_BASE'] = $fake->baseUrl;
putenv('COMPLIANTY_API_BASE=' . $fake->baseUrl);

(new TestBootstrapper())
    ->setClassLoader($loader)
    ->setProjectDir($shopRoot)
    ->setLoadEnvFile(true)
    ->setForceInstallPlugins(true)
    ->addCallingPlugin(__DIR__ . '/../composer.json')
    ->bootstrap();
