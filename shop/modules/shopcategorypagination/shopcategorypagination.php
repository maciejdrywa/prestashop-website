<?php

declare(strict_types=1);

use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ShopCategoryPagination extends Module
{
    public const QUERY_HOOK = 'actionProductSearchProviderRunQueryBefore';
    private const PAGE_SIZE = 36;

    public function __construct()
    {
        $this->name = 'shopcategorypagination';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'PAN DOMU';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        parent::__construct();
        $this->displayName = 'Paginacja kategorii';
        $this->description = 'Ustawia 36 produktów na stronie kategorii i ogranicza rozmiar zapytania.';
    }

    public function install()
    {
        return parent::install() && $this->registerHook(self::QUERY_HOOK);
    }

    /** Ustawia limit przed zapytaniem, również dla sortowania i filtrów AJAX. */
    public function hookActionProductSearchProviderRunQueryBefore(array $params): void
    {
        $query = $params['query'] ?? null;
        if (!$query instanceof ProductSearchQuery || $query->getQueryType() !== 'category') {
            return;
        }

        $requested = (int) Tools::getValue('resultsPerPage');
        $query->setResultsPerPage($requested > 0 ? min($requested, self::PAGE_SIZE) : self::PAGE_SIZE);
    }
}
