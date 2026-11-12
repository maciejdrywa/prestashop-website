<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/content.php';

try {
    $definitions = ShopInit\informationalPageDefinitions();
    $templates = ShopInit\informationalTemplates();
    if (count($definitions) !== 5 || array_keys($definitions) !== array_keys($templates)
        || count(array_unique(array_column($definitions, 'slug'))) !== 5) {
        throw new RuntimeException('Five distinct information pages are required.');
    }
    $tokens = ['{{contact_url}}'=>'/kontakt', '{{account_url}}'=>'/moje-konto',
        '{{delivery_methods}}'=>'<ul><li>Dostawa testowa</li></ul>', '{{payment_methods}}'=>'<ul><li>Płatność testowa</li></ul>'];
    foreach ($definitions as $key=>$page) $tokens['{{'.$key.'_url}}'] = '/content/' . $page['slug'];
    foreach ($templates as $html) {
        $rendered = ShopInit\renderInformationalTemplate($html, $tokens);
        if (str_contains($rendered, '{{') || !str_contains($rendered, '/kontakt')
            || !mb_check_encoding($rendered, 'UTF-8')) throw new RuntimeException('Invalid rendered template.');
    }
    $failed = false;
    try { ShopInit\renderInformationalTemplate('<p>{{missing_url}}</p>', $tokens); }
    catch (RuntimeException $error) { $failed = true; }
    if (!$failed) throw new RuntimeException('Unresolved link was accepted.');
    $failed = false;
    try { ShopInit\informationalTemplates(__DIR__ . '/missing-content-directory'); }
    catch (RuntimeException $error) { $failed = true; }
    if (!$failed) throw new RuntimeException('Missing content files were accepted.');
    echo "OK: five distinct pages, UTF-8, resolved cross-links and missing/unknown template checks. No database changes.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILED: '.$error->getMessage().PHP_EOL);
    exit(1);
}
