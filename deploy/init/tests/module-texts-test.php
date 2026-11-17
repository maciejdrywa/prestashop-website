<?php
declare(strict_types=1);

/**
 * Test definicji bloków informacyjnych, celów CMS i etykiet listy życzeń.
 *
 * Wywołanie jako www-data w kontenerze z danymi wymaganymi przez scenariusz
 * php /opt/shop/init/tests/module-texts-test.php
 *
 * Sprawdza statyczne definicje i długości tekstów bez wykonywania konfiguracji modułów.
 * Kod zakończenia 0 oznacza spełnienie asercji, a 1 nieudany scenariusz.
 */

require_once __DIR__ . '/../lib/module-texts.php';

try {
    // Ten test sprawdza kontrakt definicji i limity pól tekstowych. Weryfikacja
    // zapisanych bloków oraz ich prezentacji jest odrębnym zakresem konfiguracji sklepu.
    $definitions=ShopInit\reassuranceDefinitions();
    if (count($definitions)!==3 || array_column($definitions,'page')!==['privacy','delivery','terms']) throw new RuntimeException('Incorrect policy-page mapping.');
    foreach($definitions as $definition) {
        if(mb_strlen($definition['description'])>255 || !mb_check_encoding($definition['title'],'UTF-8')) throw new RuntimeException('Invalid module text.');
    }
    if(count(ShopInit\wishlistWording())!==3) throw new RuntimeException('Wishlist wording is incomplete.');
    echo "OK: Polish block definitions, CMS targets, storage limits and wishlist labels.\n";
} catch(Throwable $error) {
    fwrite(STDERR,'TEST FAILED: '.$error->getMessage().PHP_EOL);
    exit(1);
}
