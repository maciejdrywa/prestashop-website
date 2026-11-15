<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/module-texts.php';

try {
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
