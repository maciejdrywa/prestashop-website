<?php
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Autorski moduł PAN DOMU realizujący wymagania Dyrektywy Omnibus (UE 2019/2161).
 *
 * Przechowuje historię cen produktów w dedykowanej tabeli ps_shopomnibus_price_history
 * oraz wyświetla najniższą cenę z 30 dni przed wprowadzeniem obniżki przy każdej prezentowanej
 * promocji (zarówno na karcie produktu, jak i na listingu kategorii).
 *
 * Obsługuje:
 * - Okno 30 dni przed obniżką (art. 4 ust. 2 Ustawy o prawach konsumenta)
 * - Produkty oferowane krócej niż 30 dni (art. 4 ust. 3)
 * - Sukcesywne (kolejne) obniżki cen (art. 4 ust. 4)
 * - Warianty produktów (kombinacje cech)
 */
class ShopOmnibus extends Module
{
    public const TABLE_NAME = 'shopomnibus_price_history';

    public function __construct()
    {
        $this->name = 'shopomnibus';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'PAN DOMU';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        parent::__construct();

        $this->displayName = 'Dyrektywa Omnibus — Historia i najniższa cena';
        $this->description = 'Wyświetla najniższą cenę z 30 dni przed obniżką zgodnie z dyrektywą Omnibus.';
    }

    /**
     * Instalacja modułu, utworzenie tabeli bazy danych oraz rejestracja hooków.
     *
     * @return bool
     */
    public function install(): bool
    {
        return parent::install()
            && $this->createTable()
            && $this->registerHook('displayProductPriceBlock')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionObjectProductUpdateAfter')
            && $this->registerHook('actionObjectSpecificPriceAddAfter')
            && $this->registerHook('actionObjectSpecificPriceUpdateAfter')
            && $this->registerHook('actionObjectSpecificPriceDeleteAfter')
            && $this->registerHook('actionProductAttributeUpdate');
    }

    /**
     * Odinstalowanie modułu.
     *
     * @return bool
     */
    public function uninstall(): bool
    {
        return parent::uninstall();
    }

    /**
     * Tworzy tabelę historii cen ps_shopomnibus_price_history.
     *
     * @return bool
     */
    public function createTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_NAME . '` (
            `id_price_history` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_product` INT UNSIGNED NOT NULL,
            `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
            `price_gross` DECIMAL(20, 6) NOT NULL,
            `price_net` DECIMAL(20, 6) NOT NULL,
            `is_promotional` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_price_history`),
            INDEX `idx_product_attr_date` (`id_product`, `id_product_attribute`, `date_add`),
            INDEX `idx_date_add` (`date_add`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        return (bool) Db::getInstance()->execute($sql);
    }

    /**
     * Dołącza arkusz styli CSS modułu Omnibus do nagłówka strony.
     *
     * @param array<string, mixed> $params
     * @return void
     */
    public function hookActionFrontControllerSetMedia(array $params): void
    {
        $cssPath = 'modules/' . $this->name . '/views/css/shopomnibus.css';
        $fullPath = _PS_MODULE_DIR_ . $this->name . '/views/css/shopomnibus.css';
        $version = is_file($fullPath) ? substr(hash_file('sha256', $fullPath), 0, 12) : $this->version;

        $this->context->controller->registerStylesheet(
            'shop-omnibus',
            $cssPath,
            ['media' => 'all', 'priority' => 200, 'version' => $version]
        );
    }

    /**
     * Renderuje informację o najniższej cenie w bloku cenowym (hook displayProductPriceBlock).
     *
     * Wywoływany w motywie Classic / PAN DOMU przy type="old_price" wewnątrz bloku obniżki:
     * - na karcie produktu (product-prices.tpl wewnątrz .product-discount)
     * - na miniaturach listingów (miniatures/product.tpl wewnątrz .product-price-and-shipping)
     *
     * @param array<string, mixed> $params
     * @return string
     */
    public function hookDisplayProductPriceBlock(array $params): string
    {
        $type = $params['type'] ?? '';
        if ($type !== 'old_price') {
            return '';
        }

        $productData = $params['product'] ?? null;
        if (!$productData) {
            return '';
        }

        // Sprawdzamy czy produkt ma aktywny rabat
        $hasDiscount = (bool) ($productData['has_discount'] ?? false);
        if (!$hasDiscount) {
            return '';
        }

        $idProduct = (int) ($productData['id_product'] ?? $productData['id'] ?? 0);
        if ($idProduct <= 0) {
            return '';
        }

        $idProductAttribute = (int) ($productData['id_product_attribute'] ?? 0);
        $idShop = (int) ($this->context->shop->id ?? 1);

        $omnibusInfo = self::getLowestPriceInfo($idProduct, $idProductAttribute, $idShop, $productData);
        if ($omnibusInfo === null) {
            return '';
        }

        $formattedPrice = $this->formatPrice($omnibusInfo['lowest_price']);

        // Rozróżnienie miniatury na listingu od karty produktu
        // W miniaturach brak hook_origin, a na karcie produktu hook_origin bywa ustawiony lub obecny w ścieżce
        $isListing = !($this->context->controller instanceof ProductController);

        $label = $isListing
            ? ($omnibusInfo['is_under_30_days'] ? 'Najniższa cena (nowość):' : 'Najniższa cena z 30 dni:')
            : ($omnibusInfo['is_under_30_days'] ? 'Najniższa cena od wprowadzenia towaru:' : 'Najniższa cena z 30 dni przed obniżką:');

        $this->context->smarty->assign([
            'omnibus_lowest_price' => $formattedPrice,
            'omnibus_label' => $label,
            'omnibus_is_under_30_days' => $omnibusInfo['is_under_30_days'],
            'omnibus_is_listing' => $isListing,
            'omnibus_raw_price' => $omnibusInfo['lowest_price'],
        ]);

        return $this->display(__FILE__, 'views/templates/hook/displayProductPriceBlock.tpl');
    }

    /**
     * Wyznacza najniższą cenę Omnibus dla produktu i opcjonalnego wariantu.
     *
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param int $idShop
     * @param mixed $productPresenterData Opcjonalne dane prezentera produktu
     * @return array{lowest_price: float, is_under_30_days: bool, window_start: string, promo_start: string}|null
     */
    public static function getLowestPriceInfo(
        int $idProduct,
        int $idProductAttribute = 0,
        int $idShop = 1,
        $productPresenterData = null
    ): ?array {
        $db = Db::getInstance();
        $tableName = _DB_PREFIX_ . self::TABLE_NAME;

        // 1. Sprawdź moment rozpoczęcia bieżącej obniżki (T_promo) oraz sukcesywne obniżki
        $promoStartDate = self::resolvePromotionStartDate($idProduct, $idProductAttribute, $idShop);

        // 2. Sprawdź kiedy produkt pojawił się w ofercie (date_add)
        $dateAdd = self::getProductDateAdd($idProduct);
        $now = new DateTimeImmutable('now');
        $promoDateTime = new DateTimeImmutable($promoStartDate);

        // Określenie, czy produkt był oferowany krócej niż 30 dni przed rozpoczęciem obniżki
        $productAgeDays = $promoDateTime->diff(new DateTimeImmutable($dateAdd))->days;
        $isUnder30Days = ($productAgeDays < 30);

        // 3. Wyznaczenie początku okna analizy (window_start)
        if ($isUnder30Days) {
            $windowStart = $dateAdd;
        } else {
            $windowStart = $promoDateTime->modify('-30 days')->format('Y-m-d H:i:s');
        }

        // 4. Wyznaczenie najniższej ceny w oknie [window_start, promo_start)
        // a) Cena obowiązująca na początku okna (ostatnia znana cena z momentu <= window_start)
        // Uwaga: Db::getValue() automatycznie dokleja ' LIMIT 1', więc nie dodajemy go w zapytaniu
        $sqlInitial = 'SELECT `price_gross`
            FROM `' . $tableName . '`
            WHERE `id_product` = ' . (int) $idProduct . '
              AND `id_product_attribute` = ' . (int) $idProductAttribute . '
              AND `id_shop` = ' . (int) $idShop . '
              AND `date_add` <= "' . pSQL($windowStart) . '"
            ORDER BY `date_add` DESC';
        $initialPrice = $db->getValue($sqlInitial);

        if (($initialPrice === false || $initialPrice === null) && $idProductAttribute > 0) {
            $sqlInitialFallback = 'SELECT `price_gross`
                FROM `' . $tableName . '`
                WHERE `id_product` = ' . (int) $idProduct . '
                  AND `id_product_attribute` = 0
                  AND `id_shop` = ' . (int) $idShop . '
                  AND `date_add` <= "' . pSQL($windowStart) . '"
                ORDER BY `date_add` DESC';
            $initialPrice = $db->getValue($sqlInitialFallback);
        }

        // b) Wszystkie ceny zmienione w trakcie okna (window_start, promo_start)
        $sqlWindow = 'SELECT MIN(price_gross) AS min_window_price
            FROM `' . $tableName . '`
            WHERE `id_product` = ' . (int) $idProduct . '
              AND `id_product_attribute` = ' . (int) $idProductAttribute . '
              AND `id_shop` = ' . (int) $idShop . '
              AND `date_add` > "' . pSQL($windowStart) . '"
              AND `date_add` < "' . pSQL($promoStartDate) . '"';
        $windowMin = $db->getValue($sqlWindow);

        if (($windowMin === false || $windowMin === null) && $idProductAttribute > 0) {
            $sqlWindowFallback = 'SELECT MIN(price_gross) AS min_window_price
                FROM `' . $tableName . '`
                WHERE `id_product` = ' . (int) $idProduct . '
                  AND `id_product_attribute` = 0
                  AND `id_shop` = ' . (int) $idShop . '
                  AND `date_add` > "' . pSQL($windowStart) . '"
                  AND `date_add` < "' . pSQL($promoStartDate) . '"';
            $windowMin = $db->getValue($sqlWindowFallback);
        }

        // Najniższa cena to minimum z ceny początkowej oraz zmian w trakcie okna
        $candidatePrices = [];
        if ($initialPrice !== false && $initialPrice !== null && (float) $initialPrice > 0) {
            $candidatePrices[] = (float) $initialPrice;
        }
        if ($windowMin !== false && $windowMin !== null && (float) $windowMin > 0) {
            $candidatePrices[] = (float) $windowMin;
        }

        if (!empty($candidatePrices)) {
            $lowestPrice = min($candidatePrices);
        } else {
            // Jeśli brak wpisów przed obniżką, fallback do regularnej ceny bazowej produktu
            $lowestPrice = self::getRegularPriceFallback($idProduct, $idProductAttribute, $productPresenterData);
        }

        if ($lowestPrice === null || (float) $lowestPrice <= 0) {
            return null;
        }

        return [
            'lowest_price' => round((float) $lowestPrice, 2),
            'is_under_30_days' => $isUnder30Days,
            'window_start' => $windowStart,
            'promo_start' => $promoStartDate,
        ];
    }

    /**
     * Wyznacza datę odniesienia dla obniżki (z uwzględnieniem sukcesywnych obniżek wg art. 4 ust. 4).
     *
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param int $idShop
     * @return string Data w formacie 'Y-m-d H:i:s'
     */
    public static function resolvePromotionStartDate(int $idProduct, int $idProductAttribute, int $idShop): string
    {
        $db = Db::getInstance();
        $tableName = _DB_PREFIX_ . self::TABLE_NAME;

        // Sprawdź czy w tabeli historii istnieje ciągły okres promocji (sukcesywne obniżki)
        $sql = 'SELECT `date_add`, `is_promotional`
            FROM `' . $tableName . '`
            WHERE `id_product` = ' . (int) $idProduct . '
              AND (`id_product_attribute` = ' . (int) $idProductAttribute . ' OR `id_product_attribute` = 0)
              AND `id_shop` = ' . (int) $idShop . '
            ORDER BY (`id_product_attribute` = ' . (int) $idProductAttribute . ') DESC, `date_add` DESC
            LIMIT 20';
        $rows = $db->executeS($sql);

        if (!empty($rows)) {
            // Szukamy najwcześniejszego wpisu w aktualnie trwającej nieprzerwanej serii promocji
            $earliestPromoDate = null;
            $inActivePromoSeries = false;

            foreach ($rows as $row) {
                if ((int) $row['is_promotional'] === 1) {
                    $inActivePromoSeries = true;
                    $earliestPromoDate = $row['date_add'];
                } elseif ($inActivePromoSeries) {
                    // Trafiliśmy na cenę regularną sprzed serii promocji - zatrzymujemy się
                    break;
                }
            }

            if ($earliestPromoDate !== null) {
                return $earliestPromoDate;
            }
        }

        // Sprawdź datę from w ps_specific_price
        $sqlSpecific = 'SELECT `from`
            FROM `' . _DB_PREFIX_ . 'specific_price`
            WHERE `id_product` = ' . (int) $idProduct . '
              AND (`id_product_attribute` = 0 OR `id_product_attribute` = ' . (int) $idProductAttribute . ')
            ORDER BY `id_specific_price` DESC';
        $specificFrom = $db->getValue($sqlSpecific);

        if ($specificFrom && $specificFrom !== '0000-00-00 00:00:00') {
            return $specificFrom;
        }

        // Domyślnie chwila obecna
        return date('Y-m-d H:i:s');
    }

    /**
     * Pobiera datę dodania produktu do sklepu.
     *
     * @param int $idProduct
     * @return string
     */
    private static function getProductDateAdd(int $idProduct): string
    {
        $db = Db::getInstance();

        // Sprawdź najstarszy wpis w historii cen
        $earliestHistory = $db->getValue(
            'SELECT MIN(date_add) FROM `' . _DB_PREFIX_ . self::TABLE_NAME . '` WHERE id_product = ' . (int) $idProduct
        );

        $productDateAdd = $db->getValue(
            'SELECT date_add FROM `' . _DB_PREFIX_ . 'product` WHERE id_product = ' . (int) $idProduct
        );

        if ($earliestHistory && $productDateAdd) {
            return (strtotime($earliestHistory) < strtotime($productDateAdd)) ? $earliestHistory : $productDateAdd;
        }

        return $earliestHistory ?: ($productDateAdd ?: date('Y-m-d H:i:s'));
    }

    /**
     * Pobiera regularną cenę brutto produktu jako fallback, gdy brak wpisów w historii.
     *
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param mixed $productPresenterData
     * @return float|null
     */
    private static function getRegularPriceFallback(int $idProduct, int $idProductAttribute, $productPresenterData): ?float
    {
        if (is_array($productPresenterData) || is_object($productPresenterData)) {
            $regPriceRaw = $productPresenterData['regular_price_amount'] ?? null;
            if ($regPriceRaw !== null && (float) $regPriceRaw > 0) {
                return (float) $regPriceRaw;
            }
        }

        $context = Context::getContext();
        $attrId = $idProductAttribute > 0 ? $idProductAttribute : null;

        $price = Product::getPriceStatic(
            id_product: $idProduct,
            usetax: true,
            id_product_attribute: $attrId,
            decimals: 2,
            usereduc: false,
            use_group_reduction: false,
            context: $context,
            use_customer_price: false
        );

        return $price > 0 ? (float) $price : null;
    }

    /**
     * Formatuje kwotę do wyświetlenia zgodnie z walutą sklepu (PLN).
     *
     * @param float $price
     * @return string
     */
    private function formatPrice(float $price): string
    {
        $context = $this->context;
        if (isset($context->currentLocale) && is_object($context->currentLocale) && isset($context->currency)) {
            try {
                return $context->currentLocale->formatPrice($price, $context->currency->iso_code);
            } catch (Throwable $e) {
                // Fallback poniżej
            }
        }

        return number_format($price, 2, ',', ' ') . ' zł';
    }

    /**
     * Rejestruje aktualną cenę w historii przy edycji produktu.
     *
     * @param array<string, mixed> $params
     * @return void
     */
    public function hookActionObjectProductUpdateAfter(array $params): void
    {
        $product = $params['object'] ?? null;
        if ($product instanceof Product && $product->id > 0) {
            self::recordCurrentPrice((int) $product->id, 0);
        }
    }

    /**
     * Rejestruje aktualną cenę w historii przy dodaniu promocji SpecificPrice.
     *
     * @param array<string, mixed> $params
     * @return void
     */
    public function hookActionObjectSpecificPriceAddAfter(array $params): void
    {
        $specificPrice = $params['object'] ?? null;
        if ($specificPrice instanceof SpecificPrice && $specificPrice->id_product > 0) {
            self::recordCurrentPrice((int) $specificPrice->id_product, (int) $specificPrice->id_product_attribute, true);
        }
    }

    /**
     * Rejestruje aktualną cenę w historii przy aktualizacji promocji SpecificPrice.
     *
     * @param array<string, mixed> $params
     * @return void
     */
    public function hookActionObjectSpecificPriceUpdateAfter(array $params): void
    {
        $specificPrice = $params['object'] ?? null;
        if ($specificPrice instanceof SpecificPrice && $specificPrice->id_product > 0) {
            self::recordCurrentPrice((int) $specificPrice->id_product, (int) $specificPrice->id_product_attribute, true);
        }
    }

    /**
     * Rejestruje cenę regularną w historii po usunięciu promocji SpecificPrice.
     *
     * @param array<string, mixed> $params
     * @return void
     */
    public function hookActionObjectSpecificPriceDeleteAfter(array $params): void
    {
        $specificPrice = $params['object'] ?? null;
        if ($specificPrice instanceof SpecificPrice && $specificPrice->id_product > 0) {
            self::recordCurrentPrice((int) $specificPrice->id_product, (int) $specificPrice->id_product_attribute, false);
        }
    }

    /**
     * Rejestruje aktualną cenę w historii po zmianie wariantu produktu.
     *
     * @param array<string, mixed> $params
     * @return void
     */
    public function hookActionProductAttributeUpdate(array $params): void
    {
        $idProductAttribute = (int) ($params['id_product_attribute'] ?? 0);
        if ($idProductAttribute > 0) {
            $idProduct = (int) Db::getInstance()->getValue(
                'SELECT id_product FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product_attribute = ' . $idProductAttribute
            );
            if ($idProduct > 0) {
                self::recordCurrentPrice($idProduct, $idProductAttribute);
            }
        }
    }

    /**
     * Zapisuje bieżący punkt cenowy produktu/wariantu w tabeli historii cen.
     *
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param bool|null $isPromotional
     * @param string|null $dateAdd
     * @return bool
     */
    public static function recordCurrentPrice(
        int $idProduct,
        int $idProductAttribute = 0,
        ?bool $isPromotional = null,
        ?string $dateAdd = null
    ): bool {
        $context = Context::getContext();
        $attrId = $idProductAttribute > 0 ? $idProductAttribute : null;

        $grossPrice = (float) Product::getPriceStatic(
            id_product: $idProduct,
            usetax: true,
            id_product_attribute: $attrId,
            decimals: 6,
            usereduc: true,
            use_group_reduction: false,
            context: $context,
            use_customer_price: false
        );

        $netPrice = (float) Product::getPriceStatic(
            id_product: $idProduct,
            usetax: false,
            id_product_attribute: $attrId,
            decimals: 6,
            usereduc: true,
            use_group_reduction: false,
            context: $context,
            use_customer_price: false
        );

        if ($grossPrice <= 0) {
            return false;
        }

        if ($isPromotional === null) {
            $isPromotional = SpecificPrice::getByProductId($idProduct, $idProductAttribute > 0 ? $idProductAttribute : false) ? true : false;
        }

        $idShop = (int) ($context->shop->id ?: 1);
        $date = $dateAdd ?: date('Y-m-d H:i:s');

        return (bool) Db::getInstance()->insert(self::TABLE_NAME, [
            'id_product' => (int) $idProduct,
            'id_product_attribute' => (int) $idProductAttribute,
            'id_shop' => $idShop,
            'price_gross' => $grossPrice,
            'price_net' => $netPrice,
            'is_promotional' => $isPromotional ? 1 : 0,
            'date_add' => pSQL($date),
        ]);
    }
}
