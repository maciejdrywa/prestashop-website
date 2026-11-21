<?php

class ShopDemoPaymentsValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $cart = $this->context->cart;
        $method = Tools::getValue('demo_method');
        $token = Tools::getValue('token');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || !is_string($method) || !isset(ShopDemoPayments::METHODS[$method])
            || !is_string($token) || !hash_equals(Tools::getToken(false), $token)
            || !$cart instanceof Cart || !$this->module->availableForCart($cart)
            || !(int) $cart->id_customer || !(int) $cart->id_address_invoice
            || (int) $this->context->customer->id !== (int) $cart->id_customer) {
            $this->rejectPayment();
            return;
        }
        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer) || $customer->secure_key !== $cart->secure_key) {
            $this->rejectPayment();
            return;
        }
        // Ponowienie tego samego formularza nie tworzy kolejnego zamówienia.
        $orderId = (int) Order::getIdByCartId((int) $cart->id);
        if (!$orderId) {
            $state = new OrderState((int) Configuration::get(ShopDemoPayments::WAITING_STATE));
            if (!Validate::isLoadedObject($state) || $state->deleted || $state->paid || $state->invoice
                || $state->module_name !== $this->module->name) {
                $this->rejectPayment();
                return;
            }
            $this->module->validateOrder(
                (int) $cart->id, (int) $state->id,
                (float) $cart->getOrderTotal(true, Cart::BOTH),
                ShopDemoPayments::METHODS[$method], null, [], (int) $cart->id_currency,
                false, $customer->secure_key
            );
            $orderId = (int) $this->module->currentOrder;
        }
        $this->redirectToConfirmation($orderId, $customer);
    }

    protected function redirectToConfirmation($orderId, $customer)
    {
        Tools::redirect($this->context->link->getPageLink('order-confirmation', true,
            (int) $this->context->language->id, [
                'id_cart' => (int) $this->context->cart->id, 'id_module' => (int) $this->module->id,
                'id_order' => $orderId, 'key' => $customer->secure_key,
            ]));
    }

    protected function rejectPayment()
    {
        http_response_code(400);
        $this->errors[] = 'Nie można zatwierdzić płatności demonstracyjnej. Wróć do koszyka i spróbuj ponownie.';
        $this->redirectWithNotifications($this->context->link->getPageLink('order', true));
    }
}
