<?php
declare(strict_types=1);

/**
 * Konfiguracja promocji cenowych dla produktów w sklepie.
 *
 * Wywołanie jako www-data w kontenerze shop:
 * php /opt/shop/init/configure-promotions.php [--dry-run]
 *
 * Wymaga zainicjalizowanego katalogu produktów (po imporcie produktów i kombinacji).
 * Konfiguruje co najmniej 20 produktów w promocji z poprawnymi cenami brutto i zniżkami.
 */

require_once __DIR__ . '/lib/promotions.php';

try {
    $options = array_slice($argv, 1);
    if ($options === ['--help']) {
        echo "Użycie: php configure-promotions.php [--dry-run]\n";
        exit(0);
    }
    if ($options !== [] && $options !== ['--dry-run']) {
        throw new RuntimeException('Użycie: php configure-promotions.php [--dry-run]');
    }
    $dryRun = $options === ['--dry-run'];

    echo "[1/3] Wczytywanie kontekstu PrestaShop i katalogu produktów...\n";
    $context = ShopInit\boot();
    ShopInit\priceContext($context);
    $catalog = ShopInit\readCatalog();

    echo "[2/3] Planowanie 25 promocji cenowych (procentowych i kwotowych)...\n";
    $plan = ShopInit\promotionsPlan($catalog, $context);

    if ($dryRun) {
        echo "[DRY-RUN] Zaplanowano " . count($plan) . " promocji:\n";
        foreach ($plan as $p) {
            $discount = $p['reduction_type'] === 'percentage'
                ? '-' . (int) ($p['reduction'] * 100) . '%'
                : '-' . number_format($p['reduction'], 2, ',', ' ') . ' zł';
            echo sprintf(
                "  ID %s (PS %d): %s | %s zł -> %s (%s zł)\n",
                $p['source_id'],
                $p['product_id'],
                mb_substr($p['name'], 0, 35),
                number_format($p['regular_gross'], 2, ',', ' '),
                $discount,
                number_format($p['expected_promo_gross'], 2, ',', ' ')
            );
        }
        echo "OK: tryb próbny zakończony pomyślnie. Brak zmian w bazie.\n";
        exit(0);
    }

    echo "[3/3] Zapisywanie promocji w bazie danych i czyszczenie pamięci podręcznej...\n";
    $stats = ShopInit\transaction(fn () => ShopInit\configurePromotions($plan, $context));

    echo sprintf(
        "OK: Skonfigurowano %d promocji cenowych (utworzono: %d, zaktualizowano: %d, bez zmian: %d).\n",
        count($plan),
        $stats['created'],
        $stats['updated'],
        $stats['unchanged']
    );
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'BŁĄD konfiguracji promocji: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
