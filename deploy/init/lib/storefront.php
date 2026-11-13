<?php
declare(strict_types=1);
namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/prestashop.php';

const PROJECT_SHOP_NAME = 'Twoje Drzwi do Sukcesu';

function configureDeliveryLanguage(\Context $context, bool $dryRun = false): void
{
    $names = [
        'Click and collect'=>['name'=>'Odbiór osobisty', 'delay'=>'Odbiór w sklepie'],
        'My carrier'=>['name'=>'Dostawa kurierska', 'delay'=>'Dostawa następnego dnia!'],
        'My cheap carrier'=>['name'=>'Dostawa ekonomiczna', 'delay'=>'Kup więcej, aby zapłacić mniej!'],
        'My light carrier'=>['name'=>'Dostawa lekkich przesyłek', 'delay'=>'Im lżej, tym taniej!'],
    ];
    $delays = ['Buy more to pay less!'=>'Kup więcej, aby zapłacić mniej!',
        'The lighter the cheaper!'=>'Im lżej, tym taniej!',
        'Delivery next day!'=>'Dostawa następnego dnia!', 'Pick up in-store'=>'Odbiór w sklepie'];
    $languageId = (int) \Configuration::get('PS_LANG_DEFAULT');
    if ((new \Language($languageId))->iso_code !== 'pl') throw new RuntimeException('Delivery localization requires Polish as the default language.');
    $shopId = (int) $context->shop->id;
    $db = \Db::getInstance();
    $rows = $db->executeS('SELECT c.id_carrier,c.name,l.id_carrier AS language_row_id,l.delay FROM '
        . _DB_PREFIX_ . 'carrier c INNER JOIN ' . _DB_PREFIX_ . 'carrier_shop s ON s.id_carrier=c.id_carrier LEFT JOIN '
        . _DB_PREFIX_ . 'carrier_lang l ON l.id_carrier=c.id_carrier AND l.id_shop=s.id_shop AND l.id_lang='
        . $languageId . ' WHERE c.deleted=0 AND s.id_shop=' . $shopId . ' ORDER BY c.id_carrier');
    if ($rows === false) throw new RuntimeException('Cannot read delivery names.');
    $plans = [];
    foreach ($rows as $row) {
        $definition = $names[$row['name']] ?? null;
        if ($definition === null) {
            foreach ($names as $candidate) {
                if ($candidate['name'] === $row['name']) { $definition = $candidate; break; }
            }
        }
        if ($definition === null) continue;
        $delay = $row['delay'] ?? '';
        $plans[] = ['row'=>$row, 'name'=>$definition['name'], 'delay'=>$delay === '' ? $definition['delay'] : ($delays[$delay] ?? $delay)];
    }
    if ($dryRun) {
        foreach ($plans as $plan) echo 'PLAN DELIVERY: ' . $plan['row']['name'] . ' -> ' . $plan['name'] . '; ' . $plan['delay'] . PHP_EOL;
        return;
    }
    transaction(function () use ($db,$plans,$shopId,$languageId): void {
        foreach ($plans as $plan) {
            $row = $plan['row'];
            $id = (int) $row['id_carrier'];
            // Update only wording; keep IDs, references, prices and delivery restrictions intact.
            if ($row['name'] !== $plan['name'] && !$db->update('carrier',['name'=>pSQL($plan['name'])],'id_carrier=' . $id)) {
                throw new RuntimeException('Cannot translate delivery name: ' . $id);
            }
            if ($row['language_row_id'] === null) {
                if (!$db->insert('carrier_lang',['id_carrier'=>$id,'id_shop'=>$shopId,'id_lang'=>$languageId,'delay'=>pSQL($plan['delay'])])) {
                    throw new RuntimeException('Cannot create Polish delivery description: ' . $id);
                }
            } elseif ($row['delay'] !== $plan['delay'] && !$db->update('carrier_lang',['delay'=>pSQL($plan['delay'])],
                'id_carrier=' . $id . ' AND id_shop=' . $shopId . ' AND id_lang=' . $languageId)) {
                throw new RuntimeException('Cannot translate delivery description: ' . $id);
            }
            echo 'DELIVERY: ' . $plan['name'] . ' — ' . $plan['delay'] . PHP_EOL;
        }
    });
    \Tools::clearSmartyCache();
}

function configureShopName(\Context $context, bool $dryRun = false): void
{
    if ($dryRun) {
        echo 'PLAN SHOP NAME: ' . PROJECT_SHOP_NAME . PHP_EOL;
        return;
    }
    transaction(function () use ($context): void {
        if (!\Configuration::updateValue('PS_SHOP_NAME', PROJECT_SHOP_NAME, false,
            (int) $context->shop->id_shop_group, (int) $context->shop->id)) {
            throw new RuntimeException('Cannot save shop name configuration.');
        }
        if ($context->shop->name !== PROJECT_SHOP_NAME) {
            $context->shop->name = PROJECT_SHOP_NAME;
            if (!$context->shop->update()) throw new RuntimeException('Cannot save the shop record name.');
        }
    });
    $savedName = \Db::getInstance()->getValue('SELECT name FROM ' . _DB_PREFIX_ . 'shop WHERE id_shop=' . (int) $context->shop->id);
    if ($savedName !== PROJECT_SHOP_NAME || \Configuration::get('PS_SHOP_NAME') !== PROJECT_SHOP_NAME) {
        throw new RuntimeException('Shop name verification failed.');
    }
    \Tools::clearSmartyCache();
    echo 'SHOP NAME: ' . PROJECT_SHOP_NAME . PHP_EOL;
}

/** Default product signatures shipped with the pinned PrestaShop 8.2.8 image. */
function demoProductSignatures(): array
{
    return [
        'demo_1' => 'Hummingbird printed t-shirt',
        'demo_3' => 'Hummingbird printed sweater',
        'demo_6' => "The best is yet to come' Framed poster",
        'demo_5' => 'The adventure begins Framed poster',
        'demo_7' => 'Today is a good day Framed poster',
        'demo_11' => 'Mug The best is yet to come',
        'demo_12' => 'Mug The adventure begins',
        'demo_13' => 'Mug Today is a good day',
        'demo_15' => 'Mountain fox cushion',
        'demo_16' => 'Brown bear cushion',
        'demo_17' => 'Hummingbird cushion',
        'demo_18' => 'Mountain fox - Vector graphics',
        'demo_19' => 'Brown bear - Vector graphics',
        'demo_20' => 'Hummingbird - Vector graphics',
        'demo_21' => 'Pack Mug + Framed poster',
        'demo_8' => 'Mountain fox notebook',
        'demo_9' => 'Brown bear notebook',
        'demo_10' => 'Hummingbird notebook',
        'demo_14' => 'Customizable mug',
    ];
}

function isInstallerDemoProduct(string $reference, string $name): bool
{
    return (demoProductSignatures()[$reference] ?? null) === $name;
}

function demoProductPlan(\Context $context): array
{
    $references = implode(',', array_map(fn (string $reference): string => "'" . pSQL($reference) . "'", array_keys(demoProductSignatures())));
    $query = new \DbQuery();
    $query->select('p.id_product, p.reference, pl.name');
    $query->from('product', 'p');
    $query->innerJoin('product_shop', 'ps', 'ps.id_product=p.id_product AND ps.id_shop=' . (int) $context->shop->id);
    $query->innerJoin('product_lang', 'pl', 'pl.id_product=p.id_product AND pl.id_shop=ps.id_shop AND pl.id_lang=' . (int) \Configuration::get('PS_LANG_DEFAULT'));
    $query->where('p.reference IN (' . $references . ')');
    $query->orderBy('p.id_product');
    $rows = \Db::getInstance()->executeS($query);
    if ($rows === false) {
        throw new RuntimeException('Cannot identify installer demo products.');
    }
    $plan = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!isInstallerDemoProduct($row['reference'], $row['name'])) {
            echo 'WARNING: preserving modified demo reference: ' . $row['reference'] . ' (product ' . $row['id_product'] . ').' . PHP_EOL;
            continue;
        }
        if (isset($seen[$row['reference']])) {
            throw new RuntimeException('Ambiguous demo reference: ' . $row['reference']);
        }
        $seen[$row['reference']] = true;
        $plan[] = $row;
    }
    return $plan;
}

function cleanDemoStorefront(\Context $context, bool $dryRun = false): void
{
    echo "[1/3] Identifying installer demo products...\n";
    $plan = demoProductPlan($context);
    echo 'Demo products found: ' . count($plan) . PHP_EOL;
    echo "[2/3] Removing demo products through PrestaShop...\n";
    foreach ($plan as $row) {
        if ($dryRun) {
            echo 'PLAN DELETE: ' . $row['reference'] . ', ' . $row['name'] . PHP_EOL;
            continue;
        }
        $product = new \Product((int) $row['id_product'], false, null, (int) $context->shop->id);
        $product->id_shop_list = [(int) $context->shop->id];
        if (!\Validate::isLoadedObject($product) || !$product->delete()) {
            throw new RuntimeException('Cannot delete demo product ' . $row['reference'] . '. Removal may be partial; inspect the product before retrying.');
        }
        echo 'DELETED: ' . $row['reference'] . ', product ID ' . $row['id_product'] . PHP_EOL;
    }
    echo "[3/3] Disabling demonstration slides, promotional banner and text block...\n";
    foreach (['ps_imageslider', 'ps_banner', 'ps_customtext'] as $name) {
        if ($dryRun) {
            echo 'PLAN DISABLE: ' . $name . PHP_EOL;
            continue;
        }
        $module = \Module::getInstanceByName($name);
        if ($module === false || !$module->id) {
            echo 'ABSENT: ' . $name . PHP_EOL;
            continue;
        }
        if (!$module->disable()) {
            throw new RuntimeException('Cannot disable demonstration module: ' . $name);
        }
        \Cache::clean('Module::isEnabled' . $name);
        if (\Module::isEnabled($name)) {
            throw new RuntimeException('Demonstration module remains enabled: ' . $name);
        }
        echo 'DISABLED: ' . $name . PHP_EOL;
    }
    if (!$dryRun) {
        if (demoProductPlan($context) !== []) {
            throw new RuntimeException('Installer demo products remain in the current shop.');
        }
        \Tools::clearSmartyCache();
    }
    echo $dryRun ? "OK: dry run; no changes.\n" : "OK: installer demo products removed; demonstration slides, promotional banner and text block disabled.\n";
}
