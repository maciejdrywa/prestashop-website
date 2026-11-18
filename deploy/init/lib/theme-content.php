<?php

declare(strict_types=1);

namespace ShopInit;

use PrestaShop\Module\LinkList\Model\LinkBlock;
use RuntimeException;

require_once __DIR__ . '/content.php';
require_once __DIR__ . '/menu.php';

const THEME_CONTENT_HOOKS = [
    'SHOP_INIT_THEME_TILES' => 'displayHome',
    'SHOP_INIT_THEME_ABOUT' => 'displayFooterBefore',
];

function themeContentPresent(\Context $context): bool
{
    $module = \Module::getInstanceByName('ps_linklist');
    if (!$module || !\Module::isEnabled('ps_linklist')) {
        return false;
    }

    foreach (THEME_CONTENT_HOOKS as $setting => $hook) {
        $id = ownedContentId($setting, 'link_block', 'id_link_block', $context);
        if ($id === null || !$module->isRegisteredInHook($hook)
            || (int) (new LinkBlock($id))->id_hook !== (int) \Hook::getIdByName($hook)) {
            return false;
        }
    }

    return true;
}

/** Zapisuje dwa osobne bloki linków bez zmiany drzew kategorii ani treści CMS. */
function configureThemeContent(\Context $context): void
{
    $module = \Module::getInstanceByName('ps_linklist');
    if (!$module || !\Module::isEnabled('ps_linklist')) {
        throw new RuntimeException('Włącz ps_linklist przez configure-content.php.');
    }

    $featured = \Module::getInstanceByName('ps_featuredproducts');
    if (!$featured || !\Module::isEnabled('ps_featuredproducts')) {
        throw new RuntimeException('Włącz ps_featuredproducts, aby wyświetlić popularne produkty.');
    }

    // Używamy ścieżek z importu zamiast identyfikatorów konkretnej bazy.
    $tileNames = array_slice(mainMenuRoots(), 0, 4);
    $source = categoryPlan([], true);
    $categoryKeys = [];
    foreach ([...$tileNames, 'Produkty polecane'] as $name) {
        $key = json_encode([$name], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!isset($source[$key])) {
            throw new RuntimeException('Brakuje kategorii w danych źródłowych: ' . $name);
        }
        $categoryKeys[$name] = $key;
    }
    $categories = importCategories(array_intersect_key($source, array_flip($categoryKeys)), $context, false);
    $tiles = array_map(fn (string $name): int => $categories[$categoryKeys[$name]], $tileNames);
    $about = informationalPageId('about', informationalPageDefinitions()['about'], $context);
    if (!$about) {
        throw new RuntimeException('Uruchom configure-content.php przed konfiguracją sekcji o firmie.');
    }
    $blocks = [
        'SHOP_INIT_THEME_TILES' => ['Kategorie', [], $tiles],
        'SHOP_INIT_THEME_ABOUT' => ['O firmie', [$about], []],
    ];

    $featuredId = $categories[$categoryKeys['Produkty polecane']];
    transaction(function () use ($context, $module, $blocks, $featuredId): void {
        // Zmieniamy domyślną kategorię instalatora. Własny wybór administratora zachowujemy.
        $featuredCategory = (int) \Configuration::get('HOME_FEATURED_CAT');
        if (!$featuredCategory || $featuredCategory === (int) $context->shop->id_category) {
            saveShopSetting('HOME_FEATURED_CAT', $featuredId, $context);
        }
        $language = (int) \Configuration::get('PS_LANG_DEFAULT');
        foreach (THEME_CONTENT_HOOKS as $setting => $hook) {
            [$title, $cms, $categories] = $blocks[$setting];
            $id = ownedContentId($setting, 'link_block', 'id_link_block', $context);
            $block = new LinkBlock($id);
            $block->id_shop_list = [(int) $context->shop->id];
            $block->id_hook = (int) \Hook::getIdByName($hook);
            $block->position = 0;
            $block->name[$language] = $title;
            $block->content = ['cms' => $cms, 'category' => $categories, 'product' => [], 'static' => []];
            $block->custom_content[$language] = '[]';
            if (!$block->id_hook || !$block->save() || !$module->registerHook($hook)) {
                throw new RuntimeException('Nie udało się zapisać bloku: ' . $title);
            }
            saveShopSetting($setting, $block->id, $context);
        }
    });

    $module->_clearCache('');
    $featured->_clearCache('');
    \Tools::clearSmartyCache();
    prepareThemeCatalog($context);
}

/** Uruchamia indeksowanie i domyślne filtry po aktywacji zaimportowanych produktów. */
function prepareThemeCatalog(\Context $context): void
{
    $categoryTree = \Module::getInstanceByName('ps_categorytree');
    if ($categoryTree instanceof \Ps_CategoryTree && \Module::isEnabled('ps_categorytree')) {
        saveShopSetting('BLOCK_CATEG_ROOT_CATEGORY', $categoryTree::CATEGORY_ROOT_CURRENT, $context);
    }
    if (\Configuration::get('PS_SEARCH_INDEXATION') && !\Search::indexation()) {
        throw new RuntimeException('Nie udało się uzupełnić indeksu wyszukiwania.');
    }

    $facets = \Module::getInstanceByName('ps_facetedsearch');
    if (!$facets || !\Module::isEnabled('ps_facetedsearch')) {
        return;
    }

    $shopId = (int) $context->shop->id;
    $prefix = _DB_PREFIX_;
    $hasCatalogFilters = \Db::getInstance()->getValue(
        "SELECT 1 FROM {$prefix}layered_category lc
         JOIN {$prefix}category_product cp ON cp.id_category = lc.id_category
         JOIN {$prefix}product p ON p.id_product = cp.id_product
         JOIN {$prefix}product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = lc.id_shop
         WHERE lc.id_shop = {$shopId} AND lc.controller = 'category'
         AND p.reference LIKE 'pandomu-%' AND ps.active = 1",
        false
    );

    // Instalator tworzy reguły przed importem. Dodajemy szablon tylko wtedy,
    // gdy bieżące reguły w ogóle nie obejmują aktywnego katalogu projektu.
    if (!$hasCatalogFilters) {
        $facets->createDefaultTemplate();
        $facets->invalidateLayeredFilterBlockCache();
    }
}
