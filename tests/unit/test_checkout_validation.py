"""Kontrola kwot i odczytu potwierdzenia bez składania zamówień w sklepie."""

from decimal import Decimal

import pytest
from helpers.cart import Product
from helpers.checkout import assert_order_contents, read_checkout_total
from helpers.money import parse_pln_amount
from playwright.sync_api import Page, expect


@pytest.mark.parametrize(
    ("text", "expected"),
    [
        ("0,00 zł", "0.00"),
        ("914,60 zł", "914.60"),
        ("1234,56 zł", "1234.56"),
        ("1 234,56 zł", "1234.56"),
        ("1\u00a0234,56\u00a0zł", "1234.56"),
        ("1\u202f234,56\u00a0zł", "1234.56"),
        ("  12,30 zł\n", "12.30"),
    ],
)
def test_parse_pln_amount(text: str, expected: str):
    """Sprawdza dokładny odczyt groszy i różnych separatorów tysięcy."""
    assert parse_pln_amount(text) == Decimal(expected)


@pytest.mark.parametrize("text", ["", " ", "12,30 EUR", "12.30 zł", "12,3 zł", "NaN zł", "12 34,56 zł", "-1,00 zł"])
def test_reject_invalid_pln_amount(text: str):
    """Sprawdza odrzucenie pustej kwoty, innej waluty i niepoprawnego formatu."""
    with pytest.raises(AssertionError, match="Invalid PLN amount"):
        parse_pln_amount(text)


@pytest.fixture
def confirmation_page(page: Page):
    """Tworzy lokalne potwierdzenie z produktem za 100 zł i sumą 115 zł z dostawą."""
    page.set_content('''
        <div id="order-details">Sposób dostawy: InPost<br>Metoda płatności: Płatność przy odbiorze</div>
        <div id="order-items">
          <div class="order-line">
            <div class="details"><span>Test product</span></div>
            <div class="qty"><div class="row"><div>50,00 zł</div><div>2</div><div>100,00 zł</div></div></div>
          </div>
          <table><tr><td>Produkty</td><td>100,00 zł</td></tr>
            <tr class="total-value font-weight-bold"><td>Razem</td><td>115,00 zł</td></tr>
          </table>
        </div>
    ''')
    return page


def test_confirmed_total_includes_shipping(confirmation_page: Page):
    """Sprawdza porównanie końcowej należności wraz z kosztem dostawy."""
    assert_order_contents(confirmation_page, [Product("1", "Test product", "/product", 2)], Decimal("115.00"))


@pytest.mark.parametrize("expected_total", [Decimal("100.00"), Decimal("15.00")])
def test_reject_incorrect_order_total(confirmation_page: Page, expected_total: Decimal):
    """Wykrywa pominięcie dostawy oraz kwotę będącą jedynie fragmentem poprawnej sumy."""
    with pytest.raises(AssertionError, match="Order total differs from checkout"):
        assert_order_contents(confirmation_page, [Product("1", "Test product", "/product", 2)], expected_total)


def test_wait_for_confirmation_fields(confirmation_page: Page):
    """Sprawdza oczekiwanie na uzupełnienie początkowo pustej nazwy i ilości."""
    confirmation_page.evaluate('''() => {
        const name = document.querySelector('.details > span');
        const quantity = document.querySelector('.qty .row').children[1];
        name.textContent = '';
        quantity.textContent = '';
        setTimeout(() => { name.textContent = 'Test product'; }, 100);
        setTimeout(() => { quantity.textContent = '2'; }, 200);
    }''')
    assert_order_contents(confirmation_page, [Product("1", "Test product", "/product", 2)], Decimal("115.00"))


def test_read_checkout_total(page: Page):
    """Sprawdza odczyt końcowej kwoty z podsumowania kasy oraz ustawienia polskiej sesji."""
    page.set_content('<section id="js-checkout-summary"><div class="cart-total"><span class="value">1&nbsp;234,56 zł</span></div></section>')
    assert read_checkout_total(page) == Decimal("1234.56")
    assert page.evaluate("navigator.language") == "pl-PL"
    assert page.evaluate("Intl.DateTimeFormat().resolvedOptions().timeZone") == "Europe/Warsaw"


@pytest.mark.parametrize("text", ["", "115,00 EUR"])
def test_reject_missing_or_foreign_confirmation_amount(confirmation_page: Page, text: str):
    """Sprawdza, że pusta suma i obca waluta nie przechodzą kontroli potwierdzenia."""
    confirmation_page.locator("tr.total-value td").last.evaluate("(cell, text) => cell.textContent = text", text)
    expect.set_options(timeout=300)
    try:
        with pytest.raises(AssertionError):
            assert_order_contents(confirmation_page, [Product("1", "Test product", "/product", 2)], Decimal("115.00"))
    finally:
        expect.set_options(timeout=5000)
