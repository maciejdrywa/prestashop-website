<?php
declare(strict_types=1);

/**
 * Deterministyczne ustalenie masy całego produktu w kilogramach.
 *
 * Używana przez productPlans i testy źródła. Wykorzystuje dodatnią masę źródłową lub regułę demonstracyjną
 * kategorii.
 *
 * Zwraca plan i pochodzenie wartości. Zapis masy wykonuje importer produktów, a wpływ masy wariantów jest
 * zerowany oddzielnie.
 */

namespace ShopInit;

require_once __DIR__ . '/source.php';

/**
 * Wybiera dodatnią masę źródłową albo deterministyczną masę demonstracyjną w kg.
 *
 * Dodatnia masa źródłowa ma pierwszeństwo. Zastępstwo demonstracyjne zależy od najgłębszej rozpoznanej
 * kategorii, a remisy rozstrzyga nazwa. Wynik opisuje pochodzenie masy i dotyczy całego produktu
 * niezależnie od wariantu.
 *
 * @param array $source Pojedynczy rekord źródłowy produktu.
 * @return array{weight: string, weight_origin: string, weight_rule: string} Masa w kg do sześciu miejsc i pochodzenie wartości.
 * @throws \RuntimeException Przy nieliczbowej, nieskończonej lub ujemnej masie źródłowej.
 */
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
    // Szczegółowe kategorie mają pierwszeństwo. Sortowanie uniezależnia wynik
    // od kolejności przypisań w JSON, bez losowania i bez wpływu wariantów.
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
