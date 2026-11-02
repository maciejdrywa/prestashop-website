<?php
require "/var/www/html/config/config.inc.php";

$settings = [
    'PS_MAIL_METHOD' => 2,
    'PS_MAIL_SERVER' => 'mailpit',
    'PS_MAIL_SMTP_PORT' => 1025,
    'PS_MAIL_SMTP_ENCRYPTION' => 'off',
    'PS_MAIL_USER' => '',
    'PS_MAIL_PASSWD' => '',
    'PS_MAIL_TYPE' => 3,
];

foreach ($settings as $name => $value) {
    if (!Configuration::updateValue($name, $value)) {
        fwrite(STDERR, "Nie udało się zapisać: {$name}\n");
        exit(1);
    }
}

echo "Skonfigurowano SMTP: mailpit:1025\n";
