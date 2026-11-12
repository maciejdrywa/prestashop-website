<?php
declare(strict_types=1);

if (!defined('_PS_VERSION_')) exit;

/** Informational notice for the educational storefront; no theme overrides. */
class ShopCookieInfo extends Module
{
    public const DISMISSAL_MAX_AGE = 180 * 24 * 60 * 60;

    public function __construct()
    {
        $this->name = 'shopcookieinfo';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Twoje Drzwi do Sukcesu';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min'=>'8.0.0', 'max'=>_PS_VERSION_];
        parent::__construct();
        $this->displayName = 'Informacja o plikach cookies';
        $this->description = 'Polski komunikat z odnośnikiem do polityki prywatności i zapamiętywaniem zamknięcia.';
    }

    public function install()
    {
        return parent::install() && $this->registerHook('displayAfterBodyOpeningTag')
            && $this->registerHook('actionFrontControllerSetMedia');
    }

    public function noticeCookieName(): string
    {
        return 'shop_cookie_info_v1_' . (int) $this->context->shop->id;
    }

    private function isDismissed(): bool
    {
        return ($_COOKIE[$this->noticeCookieName()] ?? null) === '1';
    }

    public function hookActionFrontControllerSetMedia($params): void
    {
        if ($this->isDismissed()) return;
        $this->context->controller->registerStylesheet('shop-cookie-info', 'modules/' . $this->name . '/views/css/notice.css',
            ['media'=>'all', 'priority'=>200, 'version'=>substr(hash_file('sha256', __DIR__.'/views/css/notice.css'),0,12)]);
        $this->context->controller->registerJavascript('shop-cookie-info', 'modules/' . $this->name . '/views/js/notice.js',
            ['position'=>'bottom', 'priority'=>200, 'version'=>substr(hash_file('sha256', __DIR__.'/views/js/notice.js'),0,12)]);
    }

    public function hookDisplayAfterBodyOpeningTag($params): string
    {
        if ($this->isDismissed()) return '';
        $escape = fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $path = $this->context->shop->getBaseURI();
        if (!preg_match('~^/[^\s;,]*$~', $path)) $path = '/';
        $cms = new CMS((int) Configuration::get('SHOP_COOKIE_INFO_CMS_ID'), (int) $this->context->language->id, (int) $this->context->shop->id);
        $privacy = '';
        if (Validate::isLoadedObject($cms) && $cms->active) {
            $privacy = ' <a href="' . $escape($this->context->link->getCMSLink($cms,null,true)) . '">Polityka prywatności</a>.';
        }
        return '<aside id="shop-cookie-info" class="shop-cookie-info" aria-label="Informacja o plikach cookies"'
            . ' data-cookie-name="' . $escape($this->noticeCookieName()) . '" data-cookie-path="' . $escape($path)
            . '" data-cookie-max-age="' . self::DISMISSAL_MAX_AGE . '">'
            . '<div class="shop-cookie-info__inner"><p><strong>Pliki cookies.</strong> Używamy plików cookies do obsługi koszyka, logowania i ustawień sklepu.'
            . $privacy . '</p><button type="button" class="btn btn-secondary shop-cookie-info__dismiss" data-cookie-dismiss hidden>Rozumiem</button></div></aside>';
    }
}
