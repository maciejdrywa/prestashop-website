"""Pełny scenariusz zakupu w świeżej sesji przeglądarki: od koszyka przez zamówienie do pobrania faktury VAT."""

from helpers.admin import (
    assert_invoice_generated,
    change_order_status,
    login_admin,
    open_admin_order,
    read_admin_credentials,
)
from helpers.cart import prepare_cart
from helpers.checkout import (
    assert_order_contents,
    confirm_order,
    read_checkout_total,
    register_customer,
    save_address,
    select_cash_on_delivery,
    select_shipping_method,
    start_checkout,
)
from helpers.invoice import assert_invoice_pdf
from helpers.orders import (
    STATUS_AWAITING_CASH_ON_DELIVERY,
    STATUS_PAYMENT_ACCEPTED,
    assert_invoice_not_listed,
    assert_invoice_not_served,
    assert_order_status,
    assert_order_total,
    download_invoice,
    get_order_id,
    get_order_row,
    open_order_history,
)
from helpers.steps import step
from playwright.sync_api import Page


def test_cart_registration_and_order(page: Page, admin_page: Page):
    """Sprawdza koszyk, rejestrację, zakup za pobraniem, status zamówienia i pobranie faktury VAT."""
    with step("Log in to the back office"):
        # Brak lub błąd konta przerywa test przed złożeniem zamówienia, które nie dostałoby faktury.
        login_admin(admin_page, read_admin_credentials())
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
    with step("Read the final checkout total"):
        expected_total = read_checkout_total(page)
    with step("Confirm the order"):
        reference = confirm_order(page, customer)
    with step("Verify products, quantities, shipping, payment and total"):
        assert_order_contents(page, products, expected_total)

    with step("Find the order in the customer's order history"):
        open_order_history(page)
        row = get_order_row(page, reference)
        order_id = get_order_id(row)
        assert_order_total(row, expected_total)
        print(f"Order {reference}, ID {order_id}", flush=True)
    with step("Check the initial status and that no invoice is issued yet"):
        assert_order_status(row, STATUS_AWAITING_CASH_ON_DELIVERY)
        assert_invoice_not_listed(row)
        assert_invoice_not_served(page, order_id)
    with step("Accept the payment in the back office, which issues the invoice"):
        open_admin_order(admin_page, order_id, reference)
        change_order_status(admin_page, STATUS_AWAITING_CASH_ON_DELIVERY, STATUS_PAYMENT_ACCEPTED)
        assert_invoice_generated(admin_page)
    with step("Check the updated status in the order history"):
        open_order_history(page)
        row = get_order_row(page, reference)
        assert_order_status(row, STATUS_PAYMENT_ACCEPTED)
    with step("Download the VAT invoice and verify the PDF"):
        filename, content = download_invoice(page, row)
        assert filename.lower().endswith(".pdf"), f"Invoice file name is not a PDF: {filename!r}"
        page_count = assert_invoice_pdf(content, reference)
        print(f"Invoice {filename}: {len(content)} B, {page_count} page(s)", flush=True)
