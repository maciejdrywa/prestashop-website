"""Rejestracja, adres i zakup zawartości zweryfikowanego koszyka."""

import re
from collections import Counter
from dataclasses import dataclass
from decimal import Decimal
from uuid import uuid4

from playwright.sync_api import Locator, Page, expect

from helpers.cart import Product
from helpers.money import PLN_AMOUNT_PATTERN, parse_pln_amount


@dataclass(frozen=True)
class Customer:
    first_name: str
    last_name: str
    email: str


# Zamówienia testowe rozpoznajemy po prefiksie e-maila, żeby odfiltrować je
# w panelu i analityce. Domena example.com jest zarezerwowana i nie dostarcza poczty.
TEST_EMAIL_PREFIX = "e2e-"
TEST_EMAIL_DOMAIN = "example.com"

# Przewoźnicy projektu z deploy/init/lib/carriers.php; scenariusz wybiera InPost.
PROJECT_CARRIERS = ("InPost", "DPD")
SELECTED_CARRIER = "InPost"

ADDRESS = {"address1": "Testowa 12", "postcode": "00-001", "city": "Warszawa", "phone": "500600700"}


def start_checkout(page: Page) -> None:
    """Przechodzi z koszyka do zamówienia i sprawdza wyświetlenie etapu danych klienta."""
    page.locator(".checkout a").click()
    expect(page.locator("body#checkout")).to_be_attached()
    expect(page.locator("#checkout-personal-information-step.-current")).to_be_visible(timeout=15_000)


def register_customer(page: Page) -> Customer:
    """Rejestruje konto z unikalnym e-mailem i sprawdza zalogowanie oraz przejście do adresu."""
    customer = Customer("Jan", "Testowy", f"{TEST_EMAIL_PREFIX}{uuid4()}@{TEST_EMAIL_DOMAIN}")
    form = page.locator("#checkout-guest-form")
    for name, value in {"firstname": customer.first_name, "lastname": customer.last_name, "email": customer.email}.items():
        field = form.locator(f'input[name="{name}"]')
        field.fill(value)
        expect(field).to_have_value(value)
    # Hasło tworzy konto także przy włączonym zakupie gościnnym; nie logujemy go.
    password = form.locator('input[name="password"]')
    password.fill(f"E2e!aZ9-{uuid4()}")
    assert len(password.input_value()) >= 16
    for consent in form.locator('input[type="checkbox"][required]').all():
        consent.check()
        expect(consent).to_be_checked()
    form.locator('button[data-link-action="register-new-customer"]').click()
    expect(page.locator("#checkout-addresses-step.-current")).to_be_visible(timeout=15_000)
    details = page.locator("#checkout-personal-information-step")
    expect(details).to_have_class(re.compile("-complete"))
    # Sekcja identity jest dostępna dla zalogowanego klienta, nie dla gościa.
    expect(details.locator(".identity")).to_contain_text(f"{customer.first_name} {customer.last_name}")
    print(f"New account: {customer.email}", flush=True)
    return customer


def save_address(page: Page, customer: Customer) -> None:
    """Zapisuje polski adres dostawy i faktury oraz sprawdza zapisane dane i etap dostawy."""
    step = page.locator("#checkout-addresses-step")
    country = step.locator('select[name="id_country"]')
    if country.locator("option:checked").inner_text().strip() != "Polska":
        # Zmiana kraju wymienia formularz przez AJAX. Czekamy na odłączenie
        # starego pola, żeby odświeżenie nie usunęło wpisanych danych.
        previous_field = country.element_handle()
        assert previous_field is not None
        country.select_option(label="Polska")
        page.wait_for_function("element => !element.isConnected", arg=previous_field, timeout=15_000)
        previous_field.dispose()
    expect(country.locator("option:checked")).to_have_text("Polska")
    details = {"firstname": customer.first_name, "lastname": customer.last_name, **ADDRESS}
    for name, value in details.items():
        field = step.locator(f'input[name="{name}"]')
        field.fill(value)
        expect(field).to_have_value(value)
    step.locator("#use_same_address").check()
    expect(step.locator("#use_same_address")).to_be_checked()
    step.locator('button[name="confirm-addresses"]').click()
    expect(page.locator("#checkout-delivery-step.-current")).to_be_visible(timeout=15_000)
    expect(step).to_have_class(re.compile("-complete"))
    saved_address = step.locator("#delivery-addresses .address-item.selected")
    for text in [customer.first_name, customer.last_name, ADDRESS["address1"], ADDRESS["postcode"], ADDRESS["city"]]:
        expect(saved_address).to_contain_text(text)


def carrier_option(page: Page, name: str) -> Locator:
    """Zwraca opcję dostawy o dokładnie podanej nazwie przewoźnika."""
    return page.locator("#checkout-delivery-step .delivery-option").filter(
        has=page.locator(".carrier-name", has_text=re.compile(f"^{re.escape(name)}$"))
    )


def select_shipping_method(page: Page) -> None:
    """Sprawdza dostępność InPost i DPD, wybiera InPost i sprawdza zachowanie wyboru po przejściu do płatności."""
    step = page.locator("#checkout-delivery-step")
    # Wymaganie: wybór jednego z dwóch nowych przewoźników, więc oba muszą być dostępne.
    for name in PROJECT_CARRIERS:
        expect(
            carrier_option(page, name),
            f"{name} is unavailable: check shipping configuration, address and the 50 kg limit",
        ).to_have_count(1)
    option = carrier_option(page, SELECTED_CARRIER)
    radio = option.locator('input[type="radio"]')
    radio.check()
    expect(radio).to_be_checked()
    step.locator('button[name="confirmDeliveryOption"]').click()
    expect(page.locator("#checkout-payment-step.-current")).to_be_visible(timeout=15_000)
    expect(step).to_have_class(re.compile("-complete"))
    expect(radio).to_be_checked()


def select_cash_on_delivery(page: Page) -> None:
    """Wybiera pobranie, akceptuje wymagane zgody i sprawdza możliwość zatwierdzenia zakupu."""
    step = page.locator("#checkout-payment-step")
    cash_on_delivery = step.locator('input[data-module-name="ps_cashondelivery"]')
    expect(cash_on_delivery, "Cash on delivery is unavailable").to_have_count(1)
    cash_on_delivery.check()
    expect(cash_on_delivery).to_be_checked()
    option = step.locator('.payment-option:has(input[data-module-name="ps_cashondelivery"])')
    expect(option).to_contain_text(re.compile("przy odbiorze", re.I))
    for consent in step.locator("#conditions-to-approve input[required]").all():
        consent.check()
        expect(consent).to_be_checked()
    button = step.locator('#payment-confirmation button[type="submit"]')
    expect(button).to_be_enabled()
    expect(button).not_to_have_class(re.compile("disabled"))


def read_checkout_total(page: Page) -> Decimal:
    """Odczytuje końcową kwotę brutto po wyborze adresu, dostawy i płatności."""
    total = page.locator("#js-checkout-summary .cart-total .value")
    expect(total).to_be_visible()
    expect(total).to_have_text(PLN_AMOUNT_PATTERN)
    amount = parse_pln_amount(total.inner_text())
    print(f"Checkout total: {amount:.2f} PLN", flush=True)
    return amount


def confirm_order(page: Page, customer: Customer) -> str:
    """Zatwierdza zakup, sprawdza komunikat sukcesu i e-mail klienta oraz zwraca referencję zamówienia."""
    page.locator('#payment-confirmation button[type="submit"]').click()
    expect(page.locator("body#order-confirmation")).to_be_attached(timeout=15_000)
    expect(page.locator("#content-hook_order_confirmation h3")).to_contain_text(
        re.compile("zamówienie.*potwierdzone", re.I)
    )
    expect(page.locator("#content-hook_order_confirmation")).to_contain_text(customer.email)
    reference = page.locator("#order-reference-value")
    expect(reference).to_contain_text(re.compile(r":\s*\S+"))
    print(f"Confirmation: {reference.inner_text()}", flush=True)
    match = re.search(r":\s*([A-Z0-9]+)\s*$", reference.inner_text())
    assert match, f"Order reference not found in: {reference.inner_text()!r}"
    return match.group(1)


def assert_order_contents(page: Page, products: list[Product], expected_total: Decimal) -> None:
    """Sprawdza produkty, warianty, ilości, końcową kwotę brutto, InPost i pobranie."""
    details = page.locator("#order-details")
    expect(details).to_contain_text(re.compile(r"Sposób dostawy:\s*InPost", re.I))
    expect(details).to_contain_text(re.compile(r"Metoda płatności:\s*.*przy odbiorze", re.I))
    rows = page.locator("#order-items .order-line")
    expect(rows).to_have_count(len(products))
    # Classic nie podaje ID na potwierdzeniu. Porównujemy cały multizbiór
    # nazw z wariantami i ilości, także dla powtarzających się nazw.
    expected = Counter((" ".join(p.name.split()), p.quantity) for p in products)
    actual = Counter()
    for index in range(len(products)):
        row = rows.nth(index)
        name_field = row.locator(".details > span")
        quantity_field = row.locator(".qty .row > div").nth(1)
        expect(name_field).to_be_visible()
        expect(name_field).to_have_text(re.compile(r"\S"))
        expect(quantity_field).to_be_visible()
        expect(quantity_field).to_have_text(re.compile(r"^\s*[1-9][0-9]*\s*$"))
        name = " ".join((name_field.text_content() or "").split())
        quantity = int(quantity_field.inner_text().strip())
        actual[(name, quantity)] += 1
    assert actual == expected, f"Order products or quantities differ from the cart: {actual} != {expected}"

    # Porównujemy należność z dostawą i rabatami, nie sumę samych produktów.
    total_row = page.locator("#order-items tr.total-value")
    expect(total_row).to_have_count(1)
    expect(total_row).to_be_visible()
    total = total_row.locator("td").last
    expect(total).to_be_visible()
    expect(total).to_have_text(PLN_AMOUNT_PATTERN)
    actual_total = parse_pln_amount(total.inner_text())
    assert actual_total == expected_total, (
        f"Order total differs from checkout: {actual_total:.2f} PLN != {expected_total:.2f} PLN"
    )
