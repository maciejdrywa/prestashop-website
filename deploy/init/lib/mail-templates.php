<?php
declare(strict_types=1);

/**
 * Generowanie polskich wiadomości przez natywny silnik motywów poczty.
 *
 * Używana przez configure-mail.php po boot(), z wybranym motywem PrestaShop i polskim językiem domyślnym.
 *
 * Podproces bin/console zapisuje HTML/TXT w mails/pl, katalogach modułów i istniejących nadpisaniach
 * motywu. Funkcja sprawdza wynik i czyści cache Smarty.
 */

namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/prestashop.php';

/**
 * Generuje pełny polski motyw e-maili natywnym poleceniem PrestaShop, sprawdzając wynik.
 *
 * Uruchamia natywny generator z aktualnym motywem, polskim locale i --overwrite=1. Obejmuje katalog główny,
 * moduły oraz istniejące nadpisania motywu i rodzica. Weryfikuje osiem plików czterech kluczowych
 * wiadomości w obu formatach.
 *
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return void
 * @throws \RuntimeException Przy braku motywu, błędzie procesu generatora lub niekompletnych wymaganych plikach e-mail.
 */
function configurePolishMailTemplates(\Context $context): void
{
    $language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));
    if ($language->iso_code !== 'pl') throw new RuntimeException('Mail initialization requires Polish as the default language.');
    if (!function_exists('proc_open')) throw new RuntimeException('PHP proc_open is required by the native mail generator.');
    $theme = (string) (\Configuration::get('PS_MAIL_THEME') ?: 'modern');
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $theme) || !is_dir(_PS_MAIL_DIR_ . 'themes/' . $theme)) {
        throw new RuntimeException('Configured mail theme is unavailable: ' . $theme);
    }

    // Używamy tego samego mechanizmu co akcja „Generuj e-maile” w panelu,
    // z językiem polskim, skonfigurowanym motywem i zastępowaniem szablonów.
    $destinations = [['core' => _PS_MAIL_DIR_, 'modules' => _PS_MODULE_DIR_]];
    $themeFolders = [_PS_THEME_DIR_];
    $parent = $context->shop->theme->get('parent');
    if ($parent) $themeFolders[] = _PS_ROOT_DIR_ . '/themes/' . $parent . '/';
    foreach (array_unique($themeFolders) as $folder) {
        // Mail::Send nadaje szablonom motywu pierwszeństwo przed katalogiem głównym.
        // Istniejące nadpisania również regenerujemy natywnym mechanizmem sklepu.
        if (is_dir($folder . 'mails/pl')) {
            $destinations[] = ['core' => $folder . 'mails/', 'modules' => $folder . 'modules/'];
        }
    }

    foreach ($destinations as $index => $destination) {
        foreach ($destination as $folder) {
            if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
                throw new RuntimeException('Cannot create native mail output folder: ' . $folder);
            }
        }
        echo '[MAIL ' . ($index + 1) . '/' . count($destinations) . '] Generating complete native '
            . $theme . ' theme for ' . $language->locale . ' into ' . $destination['core'] . PHP_EOL;
        // Argumenty tablicowe omijają interpretację powłoki. Generator odpowiada
        // natywnej akcji panelu i nadpisuje pliki w wyznaczonym katalogu docelowym.
        $process = proc_open([
            PHP_BINARY, _PS_ROOT_DIR_ . '/bin/console', 'prestashop:mail:generate', $theme, $language->locale,
            $destination['core'], $destination['modules'], '--overwrite=1', '--no-interaction', '--env=prod',
        ], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, _PS_ROOT_DIR_);
        if (!is_resource($process)) throw new RuntimeException('Cannot start native mail generator.');
        fclose($pipes[0]);
        if (proc_close($process) !== 0) throw new RuntimeException('Native Polish mail generation failed.');

        // Sprawdzamy krytyczne wiadomości obu formatów po zakończeniu generatora.
        // Zakres generacji obejmuje pełny motyw, a ta pętla stanowi kontrolę kluczowych rezultatów.
        foreach (['account', 'bankwire', 'order_conf', 'payment'] as $template) {
            foreach (['html', 'txt'] as $extension) {
                $file = $destination['core'] . 'pl/' . $template . '.' . $extension;
                $body = is_readable($file) ? file_get_contents($file) : false;
                if ($body === false || trim($body) === '' || !mb_check_encoding($body, 'UTF-8')) {
                    throw new RuntimeException('Missing or invalid native email: ' . $file);
                }
            }
        }
    }
    \Tools::clearSmartyCache();
    echo 'OK: complete native mail theme generated in Polish, with original HTML/text and module layouts.' . PHP_EOL;
}
