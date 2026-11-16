<?php
declare(strict_types=1);
namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/prestashop.php';

function configurePolishMailTemplates(\Context $context): void
{
    $language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));
    if ($language->iso_code !== 'pl') throw new RuntimeException('Mail initialization requires Polish as the default language.');
    if (!function_exists('proc_open')) throw new RuntimeException('PHP proc_open is required by the native mail generator.');
    $theme = (string) (\Configuration::get('PS_MAIL_THEME') ?: 'modern');
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $theme) || !is_dir(_PS_MAIL_DIR_ . 'themes/' . $theme)) {
        throw new RuntimeException('Configured mail theme is unavailable: ' . $theme);
    }

    // Same command handler as the back-office Generate emails action, with
    // Polish selected, the configured mail theme and replacement enabled.
    $destinations = [['core' => _PS_MAIL_DIR_, 'modules' => _PS_MODULE_DIR_]];
    $themeFolders = [_PS_THEME_DIR_];
    $parent = $context->shop->theme->get('parent');
    if ($parent) $themeFolders[] = _PS_ROOT_DIR_ . '/themes/' . $parent . '/';
    foreach (array_unique($themeFolders) as $folder) {
        // Mail::Send gives existing theme mail overrides precedence over core.
        // Regenerate those with the native engine as well so they cannot mask it.
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
        $process = proc_open([
            PHP_BINARY, _PS_ROOT_DIR_ . '/bin/console', 'prestashop:mail:generate', $theme, $language->locale,
            $destination['core'], $destination['modules'], '--overwrite=1', '--no-interaction', '--env=prod',
        ], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, _PS_ROOT_DIR_);
        if (!is_resource($process)) throw new RuntimeException('Cannot start native mail generator.');
        fclose($pipes[0]);
        if (proc_close($process) !== 0) throw new RuntimeException('Native Polish mail generation failed.');

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
