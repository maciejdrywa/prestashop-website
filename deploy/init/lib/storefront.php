<?php
declare(strict_types=1);
namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/prestashop.php';

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
    echo "[3/3] Disabling demonstration slides and text block...\n";
    foreach (['ps_imageslider', 'ps_customtext'] as $name) {
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
    echo $dryRun ? "OK: dry run; no changes.\n" : "OK: installer demo products removed; demonstration slides and text block disabled.\n";
}
