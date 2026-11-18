<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/theme-content.php';

try {
    if (count($argv) !== 1) {
        throw new RuntimeException('Użycie: php configure-theme-content.php');
    }
    ShopInit\configureThemeContent(ShopInit\boot());
    echo "OK: skonfigurowano treści motywu i przygotowano wyszukiwanie oraz filtry.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Błąd konfiguracji treści motywu: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
