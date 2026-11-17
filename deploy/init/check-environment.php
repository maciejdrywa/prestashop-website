<?php
declare(strict_types=1);

/**
 * Kontrola wersji, kontekstu sklepu, bazy i dostępności obliczeń dziesiętnych.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/check-environment.php
 *
 * Punkt diagnostyczny oparty na lib/prestashop.php, przydatny przed importem.
 *
 * Odczytuje środowisko. boot() może przygotować katalog cache HTMLPurifier. Błąd kończy proces kodem 1.
 */

require_once __DIR__ . '/lib/prestashop.php';
try {
    echo "[1/2] Loading PrestaShop and checking database...\n";
    $context = ShopInit\boot();
    echo "[2/2] Checking decimal arithmetic...\n";
    ShopInit\rounded('1.005', 2);
    echo 'OK: PrestaShop ' . _PS_VERSION_ . ', shop ID ' . $context->shop->id . ", database and bcmath available.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
