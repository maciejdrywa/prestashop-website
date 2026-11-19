"""Operacje koszyka i oczekiwana zawartość późniejszego zamówienia."""

import math
import os
import random
from dataclasses import dataclass, replace
from urllib.parse import parse_qs, urljoin, urlparse

from playwright.sync_api import Locator, Page, expect

from helpers.steps import step


@dataclass(frozen=True)
class Product:
    id: str
    name: str
    url: str
    quantity: int


CATEGORIES = [
    ("Akcesoria meblowe", [2, 1, 3, 1, 2]),
    ("Klamki do drzwi", [1, 3, 2, 2, 1]),
]
CART_URL = "/index.php?controller=cart&action=show"
# Nawigacje nie czekają na zdjęcia i fonty. Treść sprawdzają asercje expect,
# a przed akcjami AJAX czekamy jawnie na skrypty sklepu.
WAIT_UNTIL = "domcontentloaded"


def wait_for_shop_scripts(page: Page) -> None:
    """Czeka, aż skrypty sklepu podepną obsługę przycisków koszyka.

    core.js i ps_shoppingcart podpinają kliknięcia oraz modal w funkcjach jQuery ready,
    które startują asynchronicznie po DOMContentLoaded. Funkcja dodana do tej samej
    kolejki wykona się po nich, więc kliknięcie trafi do obsługi AJAX, a nie do zwykłego
    wysłania formularza.
    """
    page.wait_for_function(
        "() => typeof window.prestashop?.emit === 'function' && typeof window.jQuery === 'function'",
        timeout=15_000,
    )
    page.evaluate("() => new Promise(resolve => window.jQuery(() => resolve()))")


def get_category_url(page: Page, name: str) -> str:
    """Zwraca adres wskazanej kategorii z menu sklepu, w razie potrzeby otwierając stronę główną."""
    link = page.get_by_role("link", name=name, exact=True).first
    # Menu jest w nagłówku każdej strony sklepu, więc stronę główną otwieramy tylko bez niego.
    if not link.count():
        page.goto("/", wait_until=WAIT_UNTIL)
    expect(link, f'Category not found "{name}" in the menu').to_be_attached()
    href = link.get_attribute("href")
    assert href, f'Category link "{name}" has no URL'
    return urljoin(page.url, href)


def get_product_urls(page: Page) -> list[str]:
    """Zbiera adresy produktów z głównej listy kategorii lub wyników wyszukiwania."""
    urls = []
    for card in page.locator("#js-product-list .product-miniature").all():
        href = card.locator(".product-title a, a.thumbnail").first.get_attribute("href")
        if href:
            urls.append(urljoin(page.url, href))
    return urls


def open_product(page: Page, url: str) -> tuple[str, str, bool, float | None]:
    """Otwiera produkt i odczytuje jego identyfikator, nazwę, dostępność oraz stan magazynowy."""
    page.goto(url, wait_until=WAIT_UNTIL)
    product_id = page.locator('#add-to-cart-or-refresh input[name="id_product"]').input_value()
    name = (page.locator("h1").first.text_content() or "").strip()
    button = page.locator("button.add-to-cart")
    available = button.count() > 0 and button.is_enabled()
    # Dokument jest już sparsowany, więc brak stanu sprawdzamy bez czekania na element.
    stock_element = page.locator(".product-quantities [data-stock]")
    stock_attribute = stock_element.first.get_attribute("data-stock") if stock_element.count() else None
    try:
        stock = float(stock_attribute) if stock_attribute is not None else None
    except ValueError:
        stock = None
    if stock is not None and not math.isfinite(stock):
        stock = None
    return product_id, name, available, stock


def add_product_to_cart(page: Page, product: Product) -> None:
    """Dodaje produkt z otwartej karty w zadanej ilości i sprawdza potwierdzenie dodania."""
    wait_for_shop_scripts(page)
    field = page.locator("#quantity_wanted")
    field.fill(str(product.quantity))
    expect(field).to_have_value(str(product.quantity))
    page.locator("button.add-to-cart").click()
    expect(page.locator("#blockcart-modal"), f"Product was not added: {product.name}").to_be_visible(timeout=15_000)


def add_products_from_category(page: Page, category: str, quantities: list[int], selected_ids: set[str]) -> list[Product]:
    """Wybiera różne dostępne produkty z kategorii i dodaje je w wymaganych ilościach."""
    category_url = get_category_url(page, category)
    added = []
    while category_url and len(added) < len(quantities):
        page.goto(category_url, wait_until=WAIT_UNTIL)
        expect(page.locator("body#category"), f"Category was not opened: {category}").to_be_attached()
        candidates = get_product_urls(page)
        assert candidates, f"Category has no products: {category}"
        next_link = page.locator(".pagination a.next").first
        href = next_link.get_attribute("href") if next_link.count() else None
        # Rozwiązujemy URL względem kategorii, zanim otworzymy produkt.
        next_page_url = urljoin(page.url, href) if href else None
        for url in candidates:
            if len(added) == len(quantities):
                break
            quantity = quantities[len(added)]
            product_id, name, available, stock = open_product(page, url)
            if product_id in selected_ids or not available or stock is None or stock < quantity:
                continue
            product = Product(product_id, name, url, quantity)
            add_product_to_cart(page, product)
            selected_ids.add(product_id)
            added.append(product)
            print(f"[{category}] {name} (id {product_id}) x {quantity}, stock: {stock:g}", flush=True)
        category_url = next_page_url
    assert len(added) == len(quantities), f"Not enough available products: {category}"
    return added


def get_cart_row(page: Page, product: Product) -> Locator:
    """Zwraca wiersz koszyka rozpoznany po identyfikatorze produktu w przycisku usuwania."""
    return page.locator(".cart-item").filter(
        has=page.locator(
            f'a.remove-from-cart[data-id-product="{product.id}"], '
            f'a.remove-from-cart[href*="id_product={product.id}&"]'
        )
    )


def assert_cart_contents(page: Page, expected: list[Product]) -> None:
    """Sprawdza liczbę pozycji koszyka, obecność oczekiwanych produktów i ich ilości."""
    page.goto(CART_URL, wait_until=WAIT_UNTIL)
    expect(page.locator(".cart-item")).to_have_count(len(expected))
    for product in expected:
        row = get_cart_row(page, product)
        expect(row, f"Missing cart product: {product.name}").to_have_count(1)
        expect(row.locator("input.js-cart-line-product-quantity"), f"Incorrect quantity: {product.name}").to_have_value(str(product.quantity))


def search_products(page: Page, query: str) -> list[str]:
    """Wyszukuje podaną frazę, sprawdza stronę wyników i zwraca adresy znalezionych produktów."""
    page.goto("/", wait_until=WAIT_UNTIL)
    field = page.locator('#search_widget input[name="s"], input.ui-autocomplete-input').first
    field.fill(query)
    field.press("Enter")
    page.wait_for_url(lambda url: parse_qs(urlparse(url).query).get("s") == [query], timeout=15_000, wait_until=WAIT_UNTIL)
    expect(page.locator("body#search")).to_be_attached()
    expect(page.locator("#js-product-list .product-miniature").first, f"No search results: {query}").to_be_visible()
    return get_product_urls(page)


def remove_products_from_cart(page: Page, products: list[Product]) -> None:
    """Usuwa wskazane produkty i po każdej operacji sprawdza zniknięcie pozycji oraz licznik."""
    page.goto(CART_URL, wait_until=WAIT_UNTIL)
    wait_for_shop_scripts(page)
    rows = page.locator(".cart-item")
    count = rows.count()
    for product in products:
        row = get_cart_row(page, product)
        expect(row).to_have_count(1)
        row.locator("a.remove-from-cart").click()
        count -= 1
        expect(row, f"Product was not removed: {product.name}").to_have_count(0, timeout=15_000)
        expect(rows).to_have_count(count, timeout=15_000)


def prepare_cart(page: Page) -> list[Product]:
    """Wykonuje scenariusz koszyka i zwraca osiem oczekiwanych pozycji z wariantami do zakupu."""
    selected_ids: set[str] = set()
    with step("Add 10 products from two categories"):
        products = []
        for name, quantities in CATEGORIES:
            products.extend(add_products_from_category(page, name, quantities, selected_ids))
        assert len(products) == 10
        assert len(selected_ids) == 10, "Products must be distinct"
        assert_cart_contents(page, products)

    with step("Search and add a random product"):
        query = os.environ.get("FRAZA", "klamka")
        results = search_products(page, query)
        assert results, f"No search results: {query}"
        random.shuffle(results)
        random_product = None
        for url in results:
            product_id, name, available, stock = open_product(page, url)
            if product_id not in selected_ids and available and stock is not None and stock >= 1:
                random_product = Product(product_id, name, url, 1)
                break
        assert random_product is not None, f"No available product to select randomly: {query}"
        print(f"Random product: {random_product.name} (id {random_product.id})", flush=True)
        add_product_to_cart(page, random_product)
        assert_cart_contents(page, [*products, random_product])

    with step("Remove three products and verify the final cart"):
        removed_products = [products[0], products[4], products[7]]
        remove_products_from_cart(page, removed_products)
        remaining_products = [p for p in products if p not in removed_products] + [random_product]
        assert len(remaining_products) == 8
        assert_cart_contents(page, remaining_products)
        for product in removed_products:
            expect(get_cart_row(page, product)).to_have_count(0)
        ordered_products = []
        for product in remaining_products:
            row = get_cart_row(page, product)
            name = (row.locator(".product-line-info a.label").text_content() or "").strip()
            assert name == product.name
            attributes = []
            for attribute in row.locator(".product-line-info:has(span.value)").all():
                label = (attribute.locator("span.label").text_content() or "").strip()
                value = (attribute.locator("span.value").text_content() or "").strip()
                attribute_text = " ".join(part for part in (label, value) if part)
                if attribute_text:
                    attributes.append(attribute_text)
            if attributes:
                name += f" ({' - '.join(attributes)})"
            ordered_products.append(replace(product, name=name))
        return ordered_products
