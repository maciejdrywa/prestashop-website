<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/prestashop.php';
try {
    echo "[1/3] Checking currency, country and taxes...\n";
    $context = ShopInit\boot();
    ShopInit\priceContext($context);
    [$products] = ShopInit\selectProducts($argv, true);
    echo "[2/3] Checking active Polish tax rules...\n";
    $query = new DbQuery();
    $query->select('g.id_tax_rules_group, g.name, t.rate')->from('tax_rules_group', 'g');
    $query->innerJoin('tax_rule', 'r', 'r.id_tax_rules_group = g.id_tax_rules_group');
    $query->innerJoin('tax', 't', 't.id_tax = r.id_tax');
    $query->where('g.active = 1 AND g.deleted = 0 AND t.active = 1 AND t.deleted = 0');
    $query->where('r.id_country = ' . (int) Country::getByIso('PL'));
    $query->where('r.id_state = 0 AND r.zipcode_from = 0 AND r.zipcode_to = 0 AND r.behavior = 0');
    $rules = Db::getInstance()->executeS($query);
    if ($rules === false) {
        throw new RuntimeException('Cannot read tax rules.');
    }
    foreach ($rules as $rule) {
        echo json_encode($rule, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
    echo "[3/3] Checking source prices and combination modifiers...\n";
    $combinations = $adjustments = 0;
    foreach ($products as $product) {
        $plan = ShopInit\pricePlan($product);
        if (!array_filter($rules, fn (array $r): bool => bccomp((string) $r['rate'], $plan['vat'], 3) === 0)) {
            throw new RuntimeException('Missing tax rule for VAT ' . $plan['vat']);
        }
        $combinations += count($plan['variants']);
        foreach ($plan['adjustments'] as $change) {
            $adjustments++;
            echo 'ROUNDING: product ' . $product['product_id'] . ', combination ' . $change['variant_id']
                . ', net modifier ' . $change['source_modifier'] . ' -> ' . $change['import_modifier'] . PHP_EOL;
        }
    }
    echo 'OK: ' . count($products) . " products, {$combinations} combinations, {$adjustments} precision adjustments. No settings changed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
