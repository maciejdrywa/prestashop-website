<?php
declare(strict_types=1);

/**
 * Polskie treści bloków informacyjnych, kryterium opinii i listy życzeń.
 *
 * Wywoływana przez configure-module-texts.php po CMS, przy aktywnych modułach i HTTPS.
 *
 * Zapisuje ReassuranceActivity, ProductCommentCriterion i Configuration, wykorzystując identyfikację stron
 * z content.php.
 */

namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/content.php';

/**
 * Zwraca polskie nazwy bloków bezpieczeństwa, dostawy i zasad zakupów oraz ich odnośniki.
 *
 * @return array<string, array> Tytuły, ikony i klucze stron docelowych trzech bloków informacyjnych.
 */
function reassuranceDefinitions(): array
{
    return [
        'security'=>['legacy'=>'Security policy', 'title'=>'Bezpieczeństwo',
            'description'=>'', 'page'=>'privacy', 'icon'=>'security.svg'],
        'delivery'=>['legacy'=>'Delivery policy', 'title'=>'Dostawa',
            'description'=>'', 'page'=>'delivery', 'icon'=>'carrier.svg'],
        'terms'=>['legacy'=>'Return policy', 'title'=>'Zasady zakupów',
            'description'=>'', 'page'=>'terms', 'icon'=>'parcel.svg'],
    ];
}

/**
 * Zwraca polskie etykiety konfiguracyjne listy życzeń.
 *
 * @return array<string, string> Klucze konfiguracji modułu i polskie etykiety.
 */
function wishlistWording(): array
{
    return ['blockwishlist_WishlistPageName'=>'Moje listy życzeń',
        'blockwishlist_WishlistDefaultTitle'=>'Moja lista życzeń',
        'blockwishlist_CreateButtonLabel'=>'Utwórz nową listę'];
}

/**
 * Rozpoznaje blok informacyjny przez mapowanie lub wcześniejsze tłumaczenia.
 *
 * @param string $key Klucz definicji lub ustawienia używany przez wywołujący obszar konfiguracji.
 * @param array $definition Definicja identyfikacji i docelowej konfiguracji obiektu.
 * @param \Module $module Instancja modułu zapewniającego tłumaczenia bloku informacyjnego.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return int|null ID bloku znalezione przez mapowanie lub tytuł w bieżącym języku.
 * @throws \RuntimeException Przy wieloznacznym dopasowaniu lub błędzie odczytu identyfikatora.
 */
function reassuranceBlockId(string $key, array $definition, \Module $module, \Context $context): ?int
{
    $saved = (int) \Configuration::get('SHOP_INIT_REASSURANCE_' . strtoupper($key));
    $query = new \DbQuery();
    $query->select('id_psreassurance AS id')->from('psreassurance');
    if ($saved) {
        $query->where('id_psreassurance=' . $saved);
        $id = uniqueId($query);
        if ($id !== null) return $id;
    }
    $language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));
    $titles = array_unique([$definition['legacy'], $definition['title'],
        $module->getTranslator()->trans($definition['legacy'], [], 'Modules.Blockreassurance.Shop', $language->locale)]);
    $query = new \DbQuery();
    $query->select('r.id_psreassurance AS id')->from('psreassurance', 'r');
    $query->innerJoin('psreassurance_lang', 'l', 'l.id_psreassurance=r.id_psreassurance');
    $query->where('l.id_lang=' . (int) $language->id);
    $query->where('l.title IN (' . implode(',', array_map(fn (string $title): string => "'" . pSQL($title) . "'", $titles)) . ')');
    return uniqueId($query);
}

/**
 * Zapisuje polskie treści modułów informacji, opinii i listy życzeń.
 *
 * Wymaga polskiego języka, HTTPS, aktywnych modułów i gotowych CMS. Rozpoznaje trzy bloki informacyjne,
 * ustawia ich odnośniki, tłumaczy kryterium Quality i zapisuje etykiety listy życzeń.
 *
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @param bool $dryRun Przy true wykonywane są kontrole i raportowanie planu przed zapisami danego konfiguratora.
 * @return void
 * @throws \RuntimeException Przy brakujących zależnościach CMS i modułów, konflikcie bloków albo błędzie zapisu tekstów.
 */
function configureModuleTexts(\Context $context, bool $dryRun = false): void
{
    $languageId = (int) \Configuration::get('PS_LANG_DEFAULT');
    if ((new \Language($languageId))->iso_code !== 'pl') throw new RuntimeException('Polish must be the default language.');
    if (!\Configuration::get('PS_SSL_ENABLED') || !\Configuration::get('PS_SSL_ENABLED_EVERYWHERE')) {
        throw new RuntimeException('HTTPS must be enabled before displaying the connection-security message.');
    }
    $modules = [];
    foreach (['blockreassurance','productcomments','blockwishlist'] as $name) {
        $modules[$name] = \Module::getInstanceByName($name);
        if (!$modules[$name] || !$modules[$name]->id || !\Module::isEnabled($name)) {
            throw new RuntimeException('Required active module is missing: ' . $name);
        }
    }
    foreach (['blockreassurance/classes/ReassuranceActivity.php','productcomments/ProductCommentCriterion.php'] as $model) {
        if (!is_readable(_PS_MODULE_DIR_ . $model)) throw new RuntimeException('Module data model is unavailable: '.$model);
        require_once _PS_MODULE_DIR_ . $model;
    }
    $definitions = reassuranceDefinitions();
    $pageDefinitions = informationalPageDefinitions();
    $blocks = [];
    foreach ($definitions as $key=>$definition) {
        $pageId = informationalPageId($definition['page'], $pageDefinitions[$definition['page']], $context);
        if (!$pageId || !(new \CMS($pageId))->active) throw new RuntimeException('Required CMS page is missing; run configure-content.php first.');
        $blocks[$key] = ['id'=>reassuranceBlockId($key,$definition,$modules['blockreassurance'],$context), 'page_id'=>$pageId];
    }
    // Każdy blok powinien wskazywać inny rekord oraz gotową stronę CMS.
    // Kontrola przecięć mapowań zapobiega nadpisywaniu jednego bloku kilkoma definicjami.
    $blockIds = array_filter(array_column($blocks,'id'));
    if (count($blockIds) !== count(array_unique($blockIds))) throw new RuntimeException('Reassurance block mappings overlap.');
    $criteria = \Db::getInstance()->executeS('SELECT c.id_product_comment_criterion FROM ' . _DB_PREFIX_
        . 'product_comment_criterion c INNER JOIN ' . _DB_PREFIX_ . 'product_comment_criterion_lang l'
        . ' ON l.id_product_comment_criterion=c.id_product_comment_criterion WHERE l.id_lang=' . $languageId . " AND l.name='Quality'");
    if ($criteria === false) throw new RuntimeException('Cannot read product review criteria.');
    if ($dryRun) {
        foreach ($definitions as $key=>$definition) echo 'PLAN: ' . $definition['title'] . ' -> CMS ' . $blocks[$key]['page_id'] . PHP_EOL;
        echo 'PLAN: ' . count($criteria) . " review criteria and Polish wishlist wording.\nOK: dry run; no changes.\n";
        return;
    }
    echo "[1/3] Saving Polish security, delivery and shopping-policy blocks...\n";
    transaction(function () use ($context,$languageId,$definitions,$blocks,$criteria): void {
        $position = 1;
        foreach ($definitions as $key=>$definition) {
            $block = new \ReassuranceActivity($blocks[$key]['id']);
            $block->status = true;
            $block->position = $position++;
            if (!$block->id) $block->icon = '/modules/blockreassurance/views/img/reassurance/pack2/' . $definition['icon'];
            $block->type_link = \ReassuranceActivity::TYPE_LINK_CMS_PAGE;
            $block->id_cms = $blocks[$key]['page_id'];
            $block->title[$languageId] = $definition['title'];
            $block->description[$languageId] = $definition['description'];
            $block->link[$languageId] = $context->link->getCMSLink($block->id_cms,null,true,$languageId,(int)$context->shop->id);
            if (!$block->save()) throw new RuntimeException('Cannot save information block: '.$key);
            saveShopSetting('SHOP_INIT_REASSURANCE_' . strtoupper($key),$block->id,$context);
        }
        saveShopSetting('PSR_HOOK_PRODUCT', \BlockReassurance::POSITION_BELOW_HEADER, $context);
        saveShopSetting('PSR_HOOK_CHECKOUT', \BlockReassurance::POSITION_BELOW_HEADER, $context);
        echo "[2/3] Updating review criterion and wishlist labels...\n";
        // Tłumaczymy wyłącznie kryteria o zastanej nazwie Quality. Pozostałe kryteria
        // oraz dane opinii i list życzeń pozostają poza zakresem tego zapisu.
        foreach ($criteria as $row) {
            $criterion = new \ProductCommentCriterion((int)$row['id_product_comment_criterion']);
            $criterion->name[$languageId] = 'Jakość';
            if (!$criterion->update()) throw new RuntimeException('Cannot translate review criterion.');
        }
        foreach (wishlistWording() as $setting=>$wording) saveShopSetting($setting,[$languageId=>$wording],$context);
    });
    echo "[3/3] Clearing module text cache...\n";
    \Tools::clearSmartyCache();
    echo "OK: Polish information blocks, review criterion and wishlist wording configured. Customer reviews and wishlist contents were preserved.\n";
}
