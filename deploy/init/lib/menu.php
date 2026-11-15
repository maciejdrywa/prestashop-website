<?php
declare(strict_types=1);
namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/prestashop.php';

/** Root order in the source shop's navigation; merchandising branches are omitted. */
function mainMenuRoots(): array
{
    return ['Klamki do drzwi', 'Okucia drzwiowe', 'Bramy i ogrodzenia',
        'Okucia budowlane', 'Dom i ogród', 'Akcesoria meblowe'];
}

function mainMenuPlan(array $categories): array
{
    if (!array_is_list($categories) || $categories === []) {
        throw new RuntimeException('Menu requires a non-empty categories.json list.');
    }
    $plan = [];
    $positions = [];
    foreach ($categories as $category) {
        $path = $category['category_path'] ?? null;
        if (!is_array($path) || !array_is_list($path) || $path === []) {
            throw new RuntimeException('Invalid menu category_path.');
        }
        foreach ($path as $name) {
            if (!is_string($name) || trim($name) === '') {
                throw new RuntimeException('Invalid menu category name.');
            }
        }
        if (!in_array($path[0], mainMenuRoots(), true)) {
            continue;
        }
        $key = json_encode($path, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (isset($plan[$key])) {
            throw new RuntimeException('Duplicate menu category path: ' . implode(' > ', $path));
        }
        $parent = count($path) === 1 ? null : json_encode(array_slice($path, 0, -1), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $positionKey = $parent ?? '';
        $position = $positions[$positionKey] ?? 0;
        $positions[$positionKey] = $position + 1;
        $plan[$key] = ['name' => end($path), 'path' => $path, 'parent' => $parent,
            'position' => $parent === null ? array_search($path[0], mainMenuRoots(), true) : $position];
    }
    $roots = [];
    foreach (mainMenuRoots() as $name) {
        $key = json_encode([$name], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!isset($plan[$key])) {
            throw new RuntimeException('Required menu root is missing from categories.json: ' . $name);
        }
        $roots[] = $key;
    }
    foreach ($plan as $entry) {
        if ($entry['parent'] !== null && !isset($plan[$entry['parent']])) {
            throw new RuntimeException('Menu category parent is missing: ' . implode(' > ', $entry['path']));
        }
    }
    // Parents must exist before children; stable sorting retains source sibling order.
    uasort($plan, fn (array $a, array $b): int => count($a['path']) <=> count($b['path'])
        ?: ($a['parent'] === null ? $a['position'] <=> $b['position'] : 0));
    return ['roots' => $roots, 'categories' => $plan];
}

function readMainMenuPlan(?string $file = null): array
{
    $file ??= dirname(SOURCE_FILE) . '/categories.json';
    if (!is_readable($file)) {
        throw new RuntimeException('Menu source is missing or unreadable: ' . $file);
    }
    $categories = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($categories)) {
        throw new RuntimeException('Invalid menu source JSON.');
    }
    return mainMenuPlan($categories);
}

function configureMainMenu(\Context $context, array $plan, bool $dryRun = false): void
{
    echo '[1/3] Preparing source menu: ' . count($plan['roots']) . ' roots, '
        . count($plan['categories']) . " category nodes...\n";
    if ($dryRun) {
        foreach ($plan['categories'] as $entry) {
            echo 'PLAN: ' . implode(' > ', $entry['path']) . ', sibling position ' . $entry['position'] . PHP_EOL;
        }
        echo "OK: dry run; no category or menu changes.\n";
        return;
    }
    $module = \Module::getInstanceByName('ps_mainmenu');
    if (!$module || !$module->id) {
        throw new RuntimeException('Required ps_mainmenu module is not installed.');
    }
    $ids = importCategories($plan['categories'], $context);
    echo "[2/3] Restoring source order and enabling menu categories...\n";
    $shopId = (int) $context->shop->id;
    foreach ($plan['categories'] as $key => $entry) {
        $category = new \Category($ids[$key], null, $shopId);
        $category->id_shop_list = [$shopId];
        if (!$category->active) {
            $category->active = true;
            if (!$category->update()) {
                throw new RuntimeException('Cannot activate menu category: ' . $entry['name']);
            }
        }
        $position = \Db::getInstance()->getValue('SELECT position FROM ' . _DB_PREFIX_
            . 'category_shop WHERE id_category=' . $ids[$key] . ' AND id_shop=' . $shopId);
        if ($position === false || ((int) $position !== $entry['position']
            && !$category->updatePosition((int) $position < $entry['position'], $entry['position']))) {
            throw new RuntimeException('Cannot set category position: ' . implode(' > ', $entry['path']));
        }
    }
    echo "[3/3] Replacing top menu items and clearing navigation cache...\n";
    $items = implode(',', array_map(fn (string $key): string => 'CAT' . $ids[$key], $plan['roots']));
    $groupId = (int) $context->shop->id_shop_group;
    if (!\Configuration::updateValue('MOD_BLOCKTOPMENU_ITEMS', $items, false, $groupId, $shopId)
        || (string) \Configuration::get('MOD_BLOCKTOPMENU_ITEMS', null, $groupId, $shopId) !== $items
        || !$module->enable()) {
        throw new RuntimeException('Cannot save or enable the source navigation menu.');
    }
    \Cache::clean('Module::isEnabledps_mainmenu');
    // Public category-update hook clears JSON caches for every customer group.
    $module->hookActionCategoryUpdate([]);
    \Tools::clearSmartyCache();
    echo 'OK: ' . implode(' | ', mainMenuRoots()) . '; nested categories restored.' . PHP_EOL;
}
