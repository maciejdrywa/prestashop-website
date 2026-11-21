<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

/** Wyłącznie lokalne zamówienia testowe. Moduł nie łączy się z operatorami płatności. */
class ShopDemoPayments extends PaymentModule
{
    const METHODS = ['blik' => 'BLIK (demo)', 'paypal' => 'PayPal (demo)'];
    const WAITING_STATE = 'SHOP_DEMOPAYMENTS_WAITING_STATE';

    public function __construct()
    {
        $this->name = 'shopdemopayments';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Twoje Drzwi do Sukcesu';
        $this->bootstrap = true;
        $this->controllers = ['validation'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        parent::__construct();
        $this->displayName = 'BLIK i PayPal — płatności demonstracyjne';
        $this->description = 'Symulacja BLIK i PayPal bez prawdziwych transakcji i danych płatniczych.';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '8.99.99'];
    }

    public function install()
    {
        return parent::install() && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn') && $this->ensureWaitingState();
    }

    /** Status nie oznacza otrzymania środków, nie tworzy płatności ani faktury. */
    public function ensureWaitingState()
    {
        $state = new OrderState((int) Configuration::get(self::WAITING_STATE));
        if (Validate::isLoadedObject($state) && !$state->deleted && $state->module_name === $this->name) {
            return true;
        }
        $existing = (int) Db::getInstance()->getValue('SELECT id_order_state FROM ' . _DB_PREFIX_
            . "order_state WHERE module_name='shopdemopayments' AND deleted=0 ORDER BY id_order_state");
        if ($existing) {
            return Configuration::updateValue(self::WAITING_STATE, $existing);
        }
        $state = new OrderState();
        foreach (Language::getLanguages(false) as $language) {
            $state->name[(int) $language['id_lang']] = 'Oczekiwanie na potwierdzenie płatności testowej';
        }
        $state->module_name = $this->name;
        $state->color = '#4169a8';
        $state->paid = false;
        $state->invoice = false;
        $state->logable = false;
        $state->send_email = false;
        $state->hidden = false;
        $state->delivery = false;
        $state->shipped = false;
        return $state->add() && Configuration::updateValue(self::WAITING_STATE, (int) $state->id);
    }

    public function availableForCart(Cart $cart)
    {
        if (!$this->active || !Validate::isLoadedObject($cart) || !$cart->id_address_delivery) {
            return false;
        }
        $currency = new Currency((int) $cart->id_currency);
        $address = new Address((int) $cart->id_address_delivery);
        $country = new Country((int) $address->id_country);
        if (!Validate::isLoadedObject($currency) || $currency->iso_code !== 'PLN'
            || !Validate::isLoadedObject($address) || $address->deleted
            || !Validate::isLoadedObject($country) || $country->iso_code !== 'PL') {
            return false;
        }
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === $this->name) {
                return true;
            }
        }
        return false;
    }

    public function hookPaymentOptions($params)
    {
        $cart = $params['cart'] ?? $this->context->cart;
        if (!$cart instanceof Cart || !$this->availableForCart($cart)) {
            return [];
        }
        $options = [];
        foreach (self::METHODS as $method => $label) {
            $option = new PaymentOption();
            $option->setModuleName($this->name)
                ->setCallToActionText($label)
                ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
                ->setInputs([
                    'demo_method' => ['name' => 'demo_method', 'type' => 'hidden', 'value' => $method],
                    'token' => ['name' => 'token', 'type' => 'hidden', 'value' => Tools::getToken(false)],
                ])
                ->setAdditionalInformation('<p>Tryb demonstracyjny. Nie nastąpi pobranie pieniędzy. '
                    . 'Nie podawaj kodu BLIK ani danych konta PayPal. Zamówienie testowe oczekuje na potwierdzenie przez administratora.</p>');
            $options[] = $option;
        }
        return $options;
    }

    public function hookPaymentReturn($params)
    {
        return '<p>Wybrano płatność demonstracyjną. Nie pobrano pieniędzy. '
            . 'Zamówienie oczekuje na potwierdzenie płatności testowej przez administratora.</p>';
    }
}
