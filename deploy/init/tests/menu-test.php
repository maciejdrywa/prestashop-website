<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/menu.php';

function menuAssert(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}
function menuReject(array $categories): void
{
    try { ShopInit\mainMenuPlan($categories); } catch (RuntimeException $error) { return; }
    throw new RuntimeException('Invalid menu source was accepted.');
}
try {
    $fixture = array_map(fn ($name) => ['category_path'=>[$name]], array_reverse(ShopInit\mainMenuRoots()));
    $root = ShopInit\mainMenuRoots()[0];
    $fixture[] = ['category_path'=>[$root, 'Second alphabetically']];
    $fixture[] = ['category_path'=>[$root, 'First alphabetically']];
    $fixture[] = ['category_path'=>[$root, 'Second alphabetically', 'Same leaf']];
    $fixture[] = ['category_path'=>[$root, 'First alphabetically', 'Same leaf']];
    $fixture[] = ['category_path'=>['Produkty polecane']];
    $plan = ShopInit\mainMenuPlan($fixture);
    menuAssert(count($plan['categories']) === 10, 'Non-navigation branch leaked into menu');
    menuAssert(array_map(fn ($key) => $plan['categories'][$key]['name'], $plan['roots']) === ShopInit\mainMenuRoots(), 'Root source order was lost');
    $children = array_values(array_filter($plan['categories'], fn ($entry) => count($entry['path']) === 2));
    menuAssert(array_column($children, 'position') === [0,1], 'Source sibling order was lost');
    menuAssert(count(array_filter($plan['categories'], fn ($entry) => $entry['name'] === 'Same leaf')) === 2, 'Repeated leaf names lost parent identity');
    menuReject([]);
    menuReject(array_slice($fixture,1));
    menuReject([...$fixture,$fixture[0]]);
    menuReject([...$fixture,['category_path'=>[$root,'Missing parent','Leaf']]]);
    menuReject([...$fixture,['category_path'=>[$root,'']]]);
    echo "OK: root order, source sibling order, nested paths, repeated names, excluded merchandising branch and invalid source checks. No database changes.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILED: '.$error->getMessage().PHP_EOL);
    exit(1);
}
