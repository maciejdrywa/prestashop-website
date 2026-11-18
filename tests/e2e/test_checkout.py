"""W4.3: jeden pełny zakup w świeżej sesji przeglądarki."""

from helpers.cart import prepare_cart
from helpers.checkout import (
    assert_order_contents,
    confirm_order,
    register_customer,
    save_address,
    select_cash_on_delivery,
    select_shipping_method,
    start_checkout,
)
from helpers.steps import step
from playwright.sync_api import Page


def test_cart_registration_and_order(page: Page):
    """Sprawdza cały zakup zawartości koszyka przez nowego klienta w jednej sesji."""
    products = prepare_cart(page)
    with step("Proceed from the cart to checkout"):
        start_checkout(page)
    with step("Register a new account"):
        customer = register_customer(page)
    with step("Save the shipping and billing address"):
        save_address(page, customer)
    with step("Select InPost shipping"):
        select_shipping_method(page)
    with step("Select cash on delivery and accept required terms"):
        select_cash_on_delivery(page)
    with step("Confirm the order"):
        confirm_order(page, customer)
    with step("Verify products, quantities, shipping and payment"):
        assert_order_contents(page, products)
