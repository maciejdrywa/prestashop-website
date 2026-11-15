<?php
declare(strict_types=1);
namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/prestashop.php';

function polishTransactionalMailTemplates(): array
{
    return ['account'=>'Dziękujemy za utworzenie konta',
        'bankwire'=>'Oczekuje na płatność', 'order_conf'=>'Szczegóły zamówienia'];
}

function mailTemplatePlaceholders(string $contents): array
{
    preg_match_all('/\{[a-z][a-z0-9_]*\}/i',$contents,$matches);
    $keys = array_values(array_unique($matches[0]));
    sort($keys);
    return $keys;
}

function configurePolishMailTemplates(\Context $context): void
{
    $language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));
    if ($language->iso_code !== 'pl') throw new RuntimeException('Transactional email initialization requires Polish as the default language.');
    if (!function_exists('proc_open')) throw new RuntimeException('PHP proc_open is required by the native mail generator.');
    $theme = (string) (\Configuration::get('PS_MAIL_THEME') ?: 'modern');
    if (!preg_match('/^[a-zA-Z0-9_-]+$/',$theme) || !is_dir(_PS_MAIL_DIR_ . 'themes/' . $theme)) {
        throw new RuntimeException('Configured mail theme is unavailable: ' . $theme);
    }
    $output = _PS_ROOT_DIR_ . '/var/import/generated-mail-pl';
    if (!is_dir($output) && !mkdir($output,0775,true) && !is_dir($output)) throw new RuntimeException('Cannot create mail generation directory.');
    echo '[MAIL 1/3] Generating native ' . $theme . ' email templates for ' . $language->locale . '...' . PHP_EOL;
    $process = proc_open([PHP_BINARY,_PS_ROOT_DIR_.'/bin/console','prestashop:mail:generate',$theme,$language->locale,
        $output,$output,'--overwrite=1','--no-interaction','--env=prod'],
        [0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,_PS_ROOT_DIR_);
    if (!is_resource($process)) throw new RuntimeException('Cannot start native mail generator.');
    fclose($pipes[0]);
    if (proc_close($process) !== 0) throw new RuntimeException('Native Polish mail generation failed.');

    echo "[MAIL 2/3] Validating Polish content and template placeholders...\n";
    $files = [];
    $themeFolders = [_PS_THEME_DIR_];
    $parent = $context->shop->theme->get('parent');
    if ($parent) $themeFolders[] = _PS_ROOT_DIR_ . '/themes/' . $parent . '/';
    foreach (polishTransactionalMailTemplates() as $template=>$expected) {
        $destinations = [_PS_MAIL_DIR_ . 'pl/'];
        // Respect Mail::Send's precedence when a mail override already exists.
        foreach ($themeFolders as $folder) {
            $folder = $folder . 'mails/pl/';
            if (is_file($folder.$template.'.html') || is_file($folder.$template.'.txt')) $destinations[] = $folder;
        }
        foreach (['html','txt'] as $extension) {
            $source = $output . '/pl/' . $template . '.' . $extension;
            if (!is_readable($source)) throw new RuntimeException('Generated email is missing: '.$source);
            $contents = (string) file_get_contents($source);
            $contents = str_replace(['Powered by','Zapłacono w sumie'],['Oprogramowanie sklepu:','Razem (brutto)'],$contents);
            if (!mb_check_encoding($contents,'UTF-8') || !str_contains(strip_tags($contents),$expected)) {
                throw new RuntimeException('Generated email is not Polish: '.$template.'.'.$extension);
            }
            foreach (array_unique($destinations) as $folder) {
                $target = $folder.$template.'.'.$extension;
                if (is_file($target) && mailTemplatePlaceholders((string)file_get_contents($target)) !== mailTemplatePlaceholders($contents)) {
                    throw new RuntimeException('Email placeholders changed unexpectedly: '.$target);
                }
                $files[$target] = $contents;
            }
        }
    }
    echo "[MAIL 3/3] Saving account, bankwire and order-confirmation emails (HTML and text)...\n";
    foreach ($files as $target=>$contents) {
        if (is_file($target) && file_get_contents($target) === $contents) continue;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target),0775,true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Cannot create mail destination directory: '.dirname($target));
        }
        $temporary = tempnam(dirname($target),'mail-pl-');
        if ($temporary === false) throw new RuntimeException('Cannot prepare email destination: '.$target);
        try {
            if (file_put_contents($temporary,$contents) === false || !chmod($temporary,0644) || !rename($temporary,$target)) {
                throw new RuntimeException('Cannot write Polish email: '.$target);
            }
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }
    \Tools::clearSmartyCache();
    echo 'OK: Polish account, pending-bank-transfer and order-confirmation email bodies configured (' . count($files) . ' files).' . PHP_EOL;
}
