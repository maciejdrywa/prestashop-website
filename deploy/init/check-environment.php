<?php

try {
    echo "[1/3] Prestashop Loading..." . PHP_EOL;

    require "/var/www/html/config/config.inc.php";

    echo "[2/3] Checking shop context..." . PHP_EOL;

    $context = Context::getContext();

    if (!Validate::isLoadedObject($context->shop)) {
        throw new Exception("Shop context is not loaded.");
    }

    echo "[3/3] Checking database connection..." . PHP_EOL;

    $result = Db::getInstance()->getValue("SELECT 1");

    if ((string) $result !== "1") {
        throw new RuntimeException("Database connection failed.");
    }

    echo "OK: PrestaShop " . _PS_VERSION_
        . ", sklep ID " . $context->shop->id
        . ", połączenie z bazą działa."
        . PHP_EOL;

    exit(0);
} catch (Throwable $e) {
    fwrite(
        STDERR,
        "Error checking environment: " . $error->getMessage() . PHP_EOL
    );

    exit(1);
}