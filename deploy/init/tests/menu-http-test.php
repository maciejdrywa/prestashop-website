<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/menu.php';

try {
    $context = ShopInit\boot();
    $plan = ShopInit\readMainMenuPlan();
    $resolve = ['localhost:443:' . gethostbyname('nginx'), 'localhost:80:' . gethostbyname('nginx')];
    $curl = curl_init();
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_TIMEOUT=>20, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>0,
        CURLOPT_RESOLVE=>$resolve]);
    $fetch = function (string $url) use ($curl): string {
        if (parse_url($url, PHP_URL_HOST) !== 'localhost') {
            throw new RuntimeException('Menu points outside the local shop: ' . $url);
        }
        curl_setopt($curl, CURLOPT_URL, $url);
        $html = curl_exec($curl);
        if ($html === false || curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200) {
            throw new RuntimeException('Menu link failed: ' . $url . ' ' . curl_error($curl));
        }
        return $html;
    };
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $fetch('https://localhost/'));
    $xpath = new DOMXPath($dom);
    $menus = $xpath->query('//ul[@id="top-menu"]');
    if ($menus->length !== 1) {
        throw new RuntimeException('Expected exactly one top navigation tree.');
    }
    $actual = [];
    $actualOrder = [];
    $links = [];
    $walk = function (DOMElement $list, array $parent) use (&$walk, &$actual, &$actualOrder, &$links, $xpath): void {
        foreach ($xpath->query('./li', $list) as $li) {
            $anchor = $xpath->query('./a', $li)->item(0);
            if (!$anchor || !preg_match('/^category-[1-9][0-9]*$/', $li->getAttribute('id'))) {
                throw new RuntimeException('Non-category item remains in top navigation.');
            }
            $labelNode = $anchor->cloneNode(true);
            foreach (iterator_to_array($labelNode->childNodes) as $child) {
                if ($child instanceof DOMElement) $labelNode->removeChild($child);
            }
            $label = trim($labelNode->textContent);
            $path = [...$parent, $label];
            $key = json_encode($path, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if (isset($actual[$key])) throw new RuntimeException('Duplicate menu path: ' . $key);
            $actual[$key] = true;
            $parentKey = json_encode($parent, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $actualOrder[$parentKey][] = $label;
            $links[] = $anchor->getAttribute('href');
            foreach ($xpath->query('./div/ul', $li) as $children) $walk($children, $path);
        }
    };
    $walk($menus->item(0), []);
    $expectedOrder = [];
    foreach ($plan['categories'] as $key=>$entry) {
        if (!isset($actual[$key])) throw new RuntimeException('Missing menu category: ' . $key);
        $parentKey = json_encode(array_slice($entry['path'],0,-1), JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $expectedOrder[$parentKey][] = $entry['name'];
    }
    if (count($actual) !== count($plan['categories'])) throw new RuntimeException('Unexpected categories remain in menu.');
    foreach ($expectedOrder as $parent=>$siblings) {
        if (($actualOrder[$parent]??[]) !== $siblings) throw new RuntimeException('Incorrect menu sibling order under ' . $parent);
    }
    foreach ($links as $url) $fetch($url);
    echo 'OK: rendered menu contains ' . count($plan['roots']) . ' roots and ' . count($actual)
        . ' source category paths in the correct hierarchy/order; all ' . count($links) . " links returned HTTP 200.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
