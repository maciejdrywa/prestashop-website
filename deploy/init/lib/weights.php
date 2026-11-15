<?php
declare(strict_types=1);
namespace ShopInit;

require_once __DIR__ . '/source.php';

/** Demonstration weights in kg, used only when the source has no positive weight. */
function productWeightPlan(array $source): array
{
    if (($source['weight'] ?? null) !== null) {
        $weight = rounded(decimal($source['weight'], 'product ' . ($source['product_id'] ?? '?') . ' weight'), 6);
        if (bccomp($weight, '0', 6) > 0) return ['weight' => $weight, 'weight_origin' => 'source', 'weight_rule' => 'source'];
    }
    $rules = [
        'uchwyty meblowe' => '0.100000', 'wizjery' => '0.050000',
        'wkładki do drzwi' => '0.300000', 'zamki drzwiowe' => '1.000000',
        'samozamykacze' => '2.000000', 'klamki do drzwi' => '0.500000',
        'akcesoria meblowe' => '0.200000', 'bramy i ogrodzenia' => '1.500000',
        'okucia budowlane' => '1.000000', 'okucia drzwiowe' => '0.500000',
        'dom i ogród' => '1.000000', 'produkty polecane' => '1.000000',
    ];
    // More specific categories take precedence; sorting makes multiple assignments
    // independent of their order in JSON. No randomness or variant data is used.
    $candidates = [];
    foreach ($source['category_paths'] ?? [] as $path) {
        foreach ($path as $depth => $name) {
            $name = mb_strtolower(trim($name), 'UTF-8');
            if (isset($rules[$name])) $candidates[] = ['depth' => $depth, 'name' => $name];
        }
    }
    usort($candidates, fn ($a, $b): int => $b['depth'] <=> $a['depth'] ?: strcmp($a['name'], $b['name']));
    $rule = $candidates[0]['name'] ?? 'default';
    return ['weight' => $rules[$rule] ?? '1.000000', 'weight_origin' => 'demo', 'weight_rule' => $rule];
}
