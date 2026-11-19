"""Kontrola odczytu karty produktu i synchronizacji z koszykiem bez uruchamiania sklepu."""

from time import monotonic

import pytest
from helpers.cart import Product, add_product_to_cart, get_category_url, open_product, wait_for_shop_scripts
from playwright.sync_api import Page, Route, expect

SHOP = "https://shop.test"

# Uproszczone jQuery ready: funkcje startują asynchronicznie i w kolejności dodania, jak w jQuery 3.
SHOP_SCRIPTS = '''
<script>
  window.prestashop = {};
  window.jQuery = (callback) => { setTimeout(callback, 0); };
  setTimeout(() => {
    window.prestashop.emit = () => {};
    window.jQuery(() => {
      document.querySelector('button.add-to-cart').addEventListener('click', (event) => {
        event.preventDefault();
        document.querySelector('#blockcart-modal').hidden = false;
      });
    });
  }, 300);
</script>
'''


def product_html(stock: str | None, disabled: bool = False, scripts: str = "") -> str:
    """Tworzy kartę produktu w strukturze Classic z opcjonalnym stanem magazynowym."""
    quantities = f'<div class="product-quantities"><span data-stock="{stock}"></span></div>' if stock is not None else ""
    return f'''<!DOCTYPE html><html><body id="product">
        <h1> Test product </h1>
        <form id="add-to-cart-or-refresh" action="{SHOP}/cart" method="post">
          <input type="hidden" name="id_product" value="42">
          <input id="quantity_wanted" name="qty" value="1">
          <button class="add-to-cart" type="submit"{" disabled" if disabled else ""}>Dodaj</button>
        </form>
        {quantities}
        <div id="blockcart-modal" hidden>Dodano</div>
        <img src="{SHOP}/img/slow.jpg">
        {scripts}
    </body></html>'''


@pytest.fixture
def shop(page: Page):
    """Podstawia odpowiedzi sklepu, a zdjęcia zostawia bez odpowiedzi, jak bardzo wolny serwer."""
    pages: dict[str, str] = {}
    requests: list[str] = []

    def handle(route: Route):
        url = route.request.url
        requests.append(url)
        if url.endswith(".jpg"):
            return
        route.fulfill(status=200, content_type="text/html", body=pages.get(url, "<html><body>POST</body></html>"))

    page.route(f"{SHOP}/**", handle)
    yield page, pages, requests
    page.unroute_all(behavior="ignoreErrors")


@pytest.mark.parametrize(("stock", "expected"), [("5", 5.0), ("0", 0.0), ("abc", None), ("inf", None), (None, None)])
def test_open_product_reads_stock(shop, stock: str | None, expected: float | None):
    """Sprawdza odczyt stanu bez czekania na zdjęcia oraz bez stałego opóźnienia przy jego braku."""
    page, pages, _ = shop
    pages[f"{SHOP}/p"] = product_html(stock)
    start = monotonic()
    assert open_product(page, f"{SHOP}/p") == ("42", "Test product", True, expected)
    assert monotonic() - start < 0.9


def test_open_product_detects_disabled_button(shop):
    """Sprawdza rozpoznanie produktu, którego nie można dodać do koszyka."""
    page, pages, _ = shop
    pages[f"{SHOP}/p"] = product_html("5", disabled=True)
    assert open_product(page, f"{SHOP}/p")[2] is False


def test_add_product_waits_for_shop_scripts(shop):
    """Sprawdza, że kliknięcie trafia do obsługi AJAX podpiętej dopiero po DOMContentLoaded."""
    page, pages, requests = shop
    pages[f"{SHOP}/p"] = product_html("5", scripts=SHOP_SCRIPTS)
    open_product(page, f"{SHOP}/p")
    add_product_to_cart(page, Product("42", "Test product", f"{SHOP}/p", 3))
    expect(page.locator("#blockcart-modal")).to_be_visible()
    assert f"{SHOP}/cart" not in requests


def test_wait_for_shop_scripts_runs_after_ready_handlers(page: Page):
    """Sprawdza czekanie na późniejsze załadowanie skryptów i na wcześniej dodane funkcje ready."""
    page.set_content("<p>Sklep</p>")
    page.evaluate('''() => {
        window.readyOrder = [];
        setTimeout(() => {
            window.prestashop = { emit() {} };
            window.jQuery = (callback) => { setTimeout(callback, 0); };
            window.jQuery(() => window.readyOrder.push("core"));
        }, 200);
    }''')
    wait_for_shop_scripts(page)
    assert page.evaluate("window.readyOrder") == ["core"]


def test_get_category_url_uses_current_menu(shop):
    """Sprawdza, że menu z bieżącej strony nie wymaga ponownego otwarcia strony głównej."""
    page, pages, requests = shop
    pages[f"{SHOP}/p"] = '<html><body><nav><a href="/3-klamki">Klamki do drzwi</a></nav></body></html>'
    page.goto(f"{SHOP}/p")
    requests.clear()
    assert get_category_url(page, "Klamki do drzwi") == f"{SHOP}/3-klamki"
    assert requests == []


def test_get_category_url_opens_home_page(shop):
    """Sprawdza otwarcie strony głównej, gdy bieżąca strona nie ma menu kategorii."""
    page, pages, _ = shop
    pages[f"{SHOP}/"] = '<html><body><nav><a href="/6-akcesoria">Akcesoria meblowe</a></nav></body></html>'
    page.goto(f"{SHOP}/empty")
    page.context.set_default_navigation_timeout(5000)
    # Adres względny "/" rozwiązuje base_url kontekstu, więc stronę główną podstawiamy pod nim.
    page.context.route("**/", lambda route: route.fulfill(status=200, content_type="text/html", body=pages[f"{SHOP}/"]))
    assert get_category_url(page, "Akcesoria meblowe").endswith("/6-akcesoria")
