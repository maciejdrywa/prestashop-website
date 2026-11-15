<?php
declare(strict_types=1);
namespace ShopInit;

use RuntimeException;
use PrestaShop\Module\LinkList\Model\LinkBlock;

require_once __DIR__ . '/prestashop.php';

function informationalPageDefinitions(): array
{
    return [
        'about' => ['title'=>'O sklepie', 'slug'=>'o-sklepie', 'legacy_slug'=>'about-us', 'legacy_title'=>'About us'],
        'terms' => ['title'=>'Regulamin', 'slug'=>'regulamin', 'legacy_slug'=>'terms-and-conditions-of-use', 'legacy_title'=>'Terms and conditions of use'],
        'privacy' => ['title'=>'Polityka prywatności', 'slug'=>'polityka-prywatnosci', 'legacy_slug'=>'legal-notice', 'legacy_title'=>'Legal Notice'],
        'delivery' => ['title'=>'Dostawa', 'slug'=>'dostawa', 'legacy_slug'=>'delivery', 'legacy_title'=>'Delivery'],
        'payments' => ['title'=>'Formy płatności', 'slug'=>'formy-platnosci', 'legacy_slug'=>'secure-payment', 'legacy_title'=>'Secure payment'],
    ];
}

function informationalTemplates(?string $directory = null): array
{
    $directory ??= dirname(__DIR__) . '/content';
    $templates = [];
    foreach (informationalPageDefinitions() as $key=>$page) {
        $file = $directory . '/' . $key . '.html';
        if (!is_readable($file)) throw new RuntimeException('Required information page template is missing: ' . $file);
        $html = (string) file_get_contents($file);
        if (!mb_check_encoding($html, 'UTF-8') || mb_strlen(trim(strip_tags($html))) < 200
            || stripos($html, 'lorem ipsum') !== false) {
            throw new RuntimeException('Invalid or incomplete UTF-8 information page template: ' . $file);
        }
        $templates[$key] = $html;
    }
    return $templates;
}

function renderInformationalTemplate(string $html, array $tokens): string
{
    $html = strtr($html, $tokens);
    if (preg_match('/\{\{[^}]+\}\}/', $html)) throw new RuntimeException('Unresolved information page template token.');
    return $html;
}

function contentSetting(string $key, $value, \Context $context, bool $html = false): void
{
    if (!\Configuration::updateValue($key, $value, $html, (int) $context->shop->id_shop_group, (int) $context->shop->id)) {
        throw new RuntimeException('Cannot save content setting: ' . $key);
    }
}

function ownedContentId(string $setting, string $table, string $primary, \Context $context): ?int
{
    $id = (int) \Configuration::get($setting);
    if (!$id) return null;
    // All table and column names come from constants in this file, never from request input.
    $query = new \DbQuery();
    $query->select('t.' . $primary . ' AS id')->from($table, 't');
    $query->innerJoin($table . '_shop', 's', 's.' . $primary . '=t.' . $primary);
    $query->where('t.' . $primary . '=' . $id)->where('s.id_shop=' . (int) $context->shop->id);
    return uniqueId($query);
}

function informationalPageId(string $key, array $page, \Context $context): ?int
{
    $saved = ownedContentId('SHOP_INIT_CMS_' . strtoupper($key), 'cms', 'id_cms', $context);
    if ($saved !== null) return $saved;
    $query = new \DbQuery();
    $query->select('DISTINCT c.id_cms AS id')->from('cms', 'c');
    $query->innerJoin('cms_shop', 's', 's.id_cms=c.id_cms');
    $query->innerJoin('cms_lang', 'l', 'l.id_cms=c.id_cms AND l.id_shop=s.id_shop');
    $query->where('s.id_shop=' . (int) $context->shop->id);
    $query->where('l.id_lang=' . (int) \Configuration::get('PS_LANG_DEFAULT'));
    $query->where("(l.link_rewrite='" . pSQL($page['slug']) . "' OR (l.link_rewrite='"
        . pSQL($page['legacy_slug']) . "' AND l.meta_title='" . pSQL($page['legacy_title']) . "'))");
    return uniqueId($query);
}

function footerBlockId(string $key, string $title, string $legacyTitle, int $hookId, \Context $context): ?int
{
    $saved = ownedContentId('SHOP_INIT_FOOTER_' . strtoupper($key), 'link_block', 'id_link_block', $context);
    if ($saved !== null) return $saved;
    $query = new \DbQuery();
    $query->select('DISTINCT b.id_link_block AS id')->from('link_block', 'b');
    $query->innerJoin('link_block_shop', 's', 's.id_link_block=b.id_link_block');
    $query->innerJoin('link_block_lang', 'l', 'l.id_link_block=b.id_link_block');
    $query->where('s.id_shop=' . (int) $context->shop->id)->where('b.id_hook=' . $hookId);
    $query->where('l.id_lang=' . (int) \Configuration::get('PS_LANG_DEFAULT'));
    $query->where("l.name IN ('" . pSQL($title) . "','" . pSQL($legacyTitle) . "')");
    return uniqueId($query);
}

function informationalMethods(\Context $context): array
{
    $db = \Db::getInstance();
    $carriers = $db->executeS('SELECT DISTINCT c.id_carrier,c.name,c.is_free FROM ' . _DB_PREFIX_
        . 'carrier c INNER JOIN ' . _DB_PREFIX_ . 'carrier_shop s ON s.id_carrier=c.id_carrier'
        . ' WHERE c.active=1 AND c.deleted=0 AND s.id_shop=' . (int) $context->shop->id . ' ORDER BY c.id_carrier');
    $payments = $db->executeS('SELECT DISTINCT m.name FROM ' . _DB_PREFIX_ . 'module m INNER JOIN '
        . _DB_PREFIX_ . 'module_shop s ON s.id_module=m.id_module INNER JOIN ' . _DB_PREFIX_
        . 'hook_module hm ON hm.id_module=m.id_module AND hm.id_shop=s.id_shop INNER JOIN '
        . _DB_PREFIX_ . "hook h ON h.id_hook=hm.id_hook WHERE h.name='paymentOptions' AND s.id_shop="
        . (int) $context->shop->id . ' ORDER BY m.name');
    if ($carriers === false || $payments === false) throw new RuntimeException('Cannot read current delivery or payment methods.');
    $deliveryLabels = ['Click and collect'=>'Odbiór osobisty (testowy)', 'My carrier'=>'Dostawa kurierska (testowa)',
        'My cheap carrier'=>'Dostawa ekonomiczna (testowa)', 'My light carrier'=>'Dostawa lekkich przesyłek (testowa)'];
    $paymentLabels = ['ps_cashondelivery'=>'Płatność przy odbiorze (testowa)',
        'ps_wirepayment'=>'Przelew bankowy (testowy)', 'ps_checkpayment'=>'Czek (testowy)'];
    $delivery = array_map(fn (array $row): string => $deliveryLabels[$row['name']] ?? $row['name'], $carriers);
    $payment = array_map(fn (array $row): string => $paymentLabels[$row['name']] ?? 'Inna metoda udostępniona w podsumowaniu zamówienia', $payments);
    $list = function (array $labels): string {
        if ($labels === []) return '<p>Obecnie nie udostępniono żadnej metody. Spróbuj ponownie po aktualizacji konfiguracji sklepu.</p>';
        return '<ul>' . implode('', array_map(fn (string $label): string => '<li>'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>', array_unique($labels))) . '</ul>';
    };
    return ['{{delivery_methods}}'=>$list($delivery), '{{payment_methods}}'=>$list($payment)];
}

function configureInformationPages(\Context $context, array $templates, bool $dryRun = false): array
{
    $languageId = (int) \Configuration::get('PS_LANG_DEFAULT');
    if ((new \Language($languageId))->iso_code !== 'pl') throw new RuntimeException('Information pages require Polish as the default language.');
    $module = \Module::getInstanceByName('ps_linklist');
    if (!$module || !$module->id || !class_exists(LinkBlock::class)) throw new RuntimeException('Required ps_linklist module is unavailable.');
    $hookId = (int) \Hook::getIdByName('displayFooter');
    if (!$hookId) throw new RuntimeException('Required displayFooter hook is missing.');
    $rootQuery = new \DbQuery();
    $rootQuery->select('c.id_cms_category AS id')->from('cms_category', 'c');
    $rootQuery->innerJoin('cms_category_shop', 's', 's.id_cms_category=c.id_cms_category');
    $rootQuery->where('c.id_parent=0')->where('s.id_shop=' . (int) $context->shop->id);
    $rootId = uniqueId($rootQuery);
    if ($rootId === null) throw new RuntimeException('Shop root CMS category is missing.');
    $definitions = informationalPageDefinitions();
    $pageIds = [];
    foreach ($definitions as $key=>$page) $pageIds[$key] = informationalPageId($key, $page, $context);
    if (count(array_filter($pageIds)) !== count(array_unique(array_filter($pageIds)))) throw new RuntimeException('Information page mappings overlap.');
    $blockIds = [
        'products'=>footerBlockId('products', 'Produkty', 'Products', $hookId, $context),
        'information'=>footerBlockId('information', 'Informacje o sklepie', 'Our company', $hookId, $context),
    ];
    if ($blockIds['products'] !== null && $blockIds['products'] === $blockIds['information']) throw new RuntimeException('Footer block mappings overlap.');
    $tokens = informationalMethods($context);
    foreach ($definitions as $key=>$page) $tokens['{{' . $key . '_url}}'] = '#';
    $tokens['{{contact_url}}'] = '#';
    $tokens['{{account_url}}'] = '#';
    foreach ($templates as $html) {
        if (!\Validate::isCleanHtml(renderInformationalTemplate($html, $tokens))) throw new RuntimeException('Information page contains invalid HTML.');
    }
    echo "[1/3] Preparing five Polish information pages...\n";
    if ($dryRun) {
        foreach ($definitions as $key=>$page) echo 'PLAN: ' . $page['title'] . ', ' . ($pageIds[$key] === null ? 'create' : 'update ID ' . $pageIds[$key]) . PHP_EOL;
        echo "PLAN: Polish footer blocks and links to all five pages.\nOK: dry run; no changes.\n";
        return $pageIds;
    }
    transaction(function () use ($context, $languageId, $rootId, $definitions, $templates, &$pageIds, $blockIds, $hookId, $tokens): void {
        $objects = [];
        foreach ($definitions as $key=>$page) {
            $cms = new \CMS($pageIds[$key], null, (int) $context->shop->id);
            $cms->id_shop_list = [(int) $context->shop->id];
            $cms->id_cms_category = $rootId;
            $cms->active = true;
            $cms->indexation = false;
            $cms->meta_title[$languageId] = $page['title'];
            $cms->head_seo_title[$languageId] = $page['title'];
            $cms->meta_description[$languageId] = $page['title'] . ' — sklep demonstracyjny Twoje Drzwi do Sukcesu.';
            $cms->meta_keywords[$languageId] = '';
            $cms->link_rewrite[$languageId] = $page['slug'];
            if (!$cms->id) $cms->content[$languageId] = '';
            if (!$cms->save()) throw new RuntimeException('Cannot save information page: ' . $page['title']);
            $pageIds[$key] = (int) $cms->id;
            $objects[$key] = $cms;
            contentSetting('SHOP_INIT_CMS_' . strtoupper($key), $cms->id, $context);
        }
        $escape = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        foreach ($definitions as $key=>$page) {
            $tokens['{{' . $key . '_url}}'] = $escape($context->link->getCMSLink($objects[$key], $page['slug'], true, $languageId, (int) $context->shop->id));
        }
        $tokens['{{contact_url}}'] = $escape($context->link->getPageLink('contact', true, $languageId));
        $tokens['{{account_url}}'] = $escape($context->link->getPageLink('my-account', true, $languageId));
        foreach ($objects as $key=>$cms) {
            $cms->content[$languageId] = renderInformationalTemplate($templates[$key], $tokens);
            if (!$cms->update()) throw new RuntimeException('Cannot save information page text: ' . $definitions[$key]['title']);
            echo 'SAVED: ' . $definitions[$key]['title'] . ', ID ' . $cms->id . PHP_EOL;
        }
        echo "[2/3] Configuring Polish footer links...\n";
        $blocks = [
            'products'=>['title'=>'Produkty', 'content'=>['cms'=>[], 'product'=>['prices-drop','new-products','best-sales'], 'static'=>[], 'category'=>[]]],
            'information'=>['title'=>'Informacje o sklepie', 'content'=>['cms'=>array_values($pageIds), 'product'=>[], 'static'=>['contact','sitemap'], 'category'=>[]]],
        ];
        $position = 0;
        foreach ($blocks as $key=>$definition) {
            $block = new LinkBlock($blockIds[$key]);
            $block->id_shop_list = [(int) $context->shop->id];
            $block->id_hook = $hookId;
            $block->position = $position;
            $block->name[$languageId] = $definition['title'];
            $block->content = $definition['content'];
            $block->custom_content[$languageId] = '[]';
            if (!$block->save() || !\Db::getInstance()->update('link_block_shop', ['position'=>$position],
                'id_link_block=' . (int) $block->id . ' AND id_shop=' . (int) $context->shop->id)) {
                throw new RuntimeException('Cannot save footer block: ' . $definition['title']);
            }
            contentSetting('SHOP_INIT_FOOTER_' . strtoupper($key), $block->id, $context);
            $position++;
        }
        contentSetting('PS_CONDITIONS', 1, $context);
        contentSetting('PS_CONDITIONS_CMS_ID', $pageIds['terms'], $context);
        contentSetting('CUSTPRIV_MSG_AUTH', [$languageId=>'Dane wpisane w formularzu służą obsłudze konta i zamówień testowych. Używaj danych fikcyjnych. Szczegóły opisuje <a href="'
            . $tokens['{{privacy_url}}'] . '">Polityka prywatności</a>.'], $context, true);
    });
    echo "[3/3] Enabling footer module and clearing page caches...\n";
    if (!$module->enable() || !$module->registerHook('displayFooter')) throw new RuntimeException('Cannot enable the information footer.');
    \Cache::clean('Module::isEnabledps_linklist');
    $module->_clearCache('');
    \Tools::clearSmartyCache();
    echo "OK: five Polish information pages, footer links and checkout terms/privacy references configured.\n";
    return $pageIds;
}
