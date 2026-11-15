<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/storefront.php';
require_once __DIR__ . '/../lib/initialization.php';

try {
    $signatures = ShopInit\demoProductSignatures();
    if (count($signatures) !== 19) {
        throw new RuntimeException('Unexpected demo signature list.');
    }
    foreach ($signatures as $reference => $name) {
        if (!ShopInit\isInstallerDemoProduct($reference, $name)
            || ShopInit\isInstallerDemoProduct('pandomu-5', $name)
            || ShopInit\isInstallerDemoProduct($reference, 'Custom product')
            || ShopInit\isInstallerDemoProduct('demo_9999', $name)) {
            throw new RuntimeException('Demo identification does not protect other products.');
        }
    }
    if (ShopInit\initializationStages()[0]['script'] !== 'configure-storefront.php') {
        throw new RuntimeException('Storefront cleanup must precede catalog import.');
    }
    echo "OK: 19 installer signatures, protection of imported/custom products and initialization ordering. No database changes.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
