<?php
declare(strict_types=1);

/**
 * Synchronizacja plików autorskiego modułu cookies z obrazu do instalacji.
 *
 * Wywoływana po boot() przez configure-cookies.php i ścieżkę wznowienia. Źródłem jest /opt/shop-modules
 * zbudowanego obrazu.
 *
 * Podmienia zmienione pliki PHP, CSS i JS w trwałym modules/shopcookieinfo. Rejestrację hooków i zapis
 * odnośnika CMS wykonuje konfigurator.
 */

namespace ShopInit;

use RuntimeException;

/**
 * Kopiuje zmienione pliki autorskiego modułu. Zwraca informację o zmianie cache.
 *
 * Porównuje źródła zbudowanego obrazu z trwałym katalogiem modules. Atomowo podmienia poszczególne pliki i
 * zwraca flagę dla wywołującego, który decyduje o czyszczeniu cache i konfiguracji modułu.
 *
 * @return bool Czy podmieniono przynajmniej jeden plik modułu i należy odświeżyć cache zasobów.
 * @throws \RuntimeException Przy brakującym źródle modułu lub błędzie przygotowania i podmiany pliku docelowego.
 */
function installCookieModuleFiles(): bool
{
    $source = '/opt/shop-modules/shopcookieinfo/';
    $files = ['shopcookieinfo.php', 'views/css/notice.css', 'views/js/notice.js'];
    $changed = false;
    foreach ($files as $file) {
        if (!is_readable($source . $file)) {
            throw new RuntimeException('Brak pliku modułu cookies ' . $file . '. Przebuduj obraz sklepu.');
        }
        $target = _PS_MODULE_DIR_ . 'shopcookieinfo/' . $file;
        if (is_file($target) && hash_file('sha256', $target) === hash_file('sha256', $source . $file)) {
            continue;
        }
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Nie można utworzyć katalogu modułu cookies.');
        }
        // Kopia w katalogu docelowym i rename ograniczają widoczność częściowo
        // zapisanych plików. Wywołujący osobno konfiguruje moduł i odświeża jego cache.
        $temporary = tempnam(dirname($target), '.cookie-');
        if ($temporary === false) {
            throw new RuntimeException('Nie można przygotować pliku modułu cookies.');
        }
        try {
            if (!copy($source . $file, $temporary) || !chmod($temporary, 0644) || !rename($temporary, $target)) {
                throw new RuntimeException('Nie można zapisać pliku modułu cookies ' . $file);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        $changed = true;
    }
    return $changed;
}
