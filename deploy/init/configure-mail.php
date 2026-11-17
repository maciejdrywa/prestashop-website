<?php
declare(strict_types=1);

/**
 * SMTP Mailpit i generowanie natywnych polskich szablonów e-mail.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/configure-mail.php
 *
 * Wymaga istniejącego sklepu, polskiego języka i motywu poczty. Wywołuje lib/mail-templates.php.
 *
 * Zapisuje ustawienia SMTP oraz HTML/TXT w katalogach poczty. Każde samodzielne wywołanie wykonuje
 * konfigurację i generowanie.
 */

require_once __DIR__ . '/lib/mail-templates.php';
try {
    $context = ShopInit\boot();
    $settings = [
        'PS_MAIL_METHOD' => 2,
        'PS_MAIL_SERVER' => 'mailpit',
        'PS_MAIL_SMTP_PORT' => 1025,
        'PS_MAIL_SMTP_ENCRYPTION' => 'off',
        'PS_MAIL_USER' => '',
        'PS_MAIL_PASSWD' => '',
        'PS_MAIL_TYPE' => 3,
    ];
    // Ustawienia SMTP i generowanie plików to odrębne operacje. Nieudany generator
    // może pozostawić już zatwierdzoną konfigurację oraz część plików do ponownego wygenerowania.
    ShopInit\transaction(function () use ($settings): void {
        foreach ($settings as $name => $value) {
            if (!Configuration::updateValue($name, $value) || (string) Configuration::get($name) !== (string) $value) {
                throw new RuntimeException('Cannot save mail setting: ' . $name);
            }
        }
    });
    echo "OK: SMTP configured: mailpit:1025.\n";
    ShopInit\configurePolishMailTemplates($context);
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
