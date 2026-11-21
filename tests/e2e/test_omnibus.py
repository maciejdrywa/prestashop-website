"""D3.2: Weryfikacja wyświetlania najniższej ceny z 30 dni (Dyrektywa Omnibus)."""

from decimal import Decimal

from helpers.catalog import open_source_product
from helpers.money import parse_pln_amount
from helpers.steps import step
from playwright.sync_api import Page, expect


def test_omnibus_display_on_product_page(page: Page):
    """Sprawdza wyświetlanie bloku Omnibus na karcie produktu objętego promocją (> 30 dni)."""
    with step("Open promotional product pandomu-139 - Klamka NINA"):
        open_source_product(page, 139, "NINA")
        expect(page.locator("body#product")).to_be_attached(timeout=15_000)

    with step("Verify Omnibus block presence, label and lowest price"):
        omnibus_el = page.locator(".product-prices .shopomnibus-price-notice")
        expect(omnibus_el, "Omnibus notice should be visible in product-prices").to_be_visible()

        label_el = omnibus_el.locator(".shopomnibus-label")
        expect(label_el).to_be_visible()
        label_text = label_el.inner_text().strip()
        assert "Najniższa cena z 30 dni przed obniżką" in label_text, (
            f"Expected standard 30-day label, got: {label_text}"
        )

        price_el = omnibus_el.locator(".shopomnibus-price")
        expect(price_el).to_be_visible()
        lowest_amount = parse_pln_amount(price_el.inner_text().strip())
        assert lowest_amount == Decimal("42.55"), (
            f"Expected lowest price 42.55 PLN, got: {lowest_amount}"
        )


def test_omnibus_display_on_category_listing(page: Page):
    """Sprawdza obecność i treść bloku Omnibus na kafelkach produktów w promocji na listingu."""
    with step("Open category page with promotional products"):
        open_source_product(page, 12, "ZN-160")
        category_url = page.locator(".breadcrumb a").last.get_attribute("href")
        assert category_url, "Promotional product should link to its category"
        page.goto(category_url)
        expect(page.locator("body#category")).to_be_attached(timeout=15_000)

    with step("Verify Omnibus notice on product miniatures with discounts"):
        miniatures = page.locator(".product-miniature")
        count = miniatures.count()
        assert count > 0, "Category page should have product miniatures"

        # Przynajmniej jedna miniatura ma zniżkę i blok Omnibus
        omnibus_listings = page.locator(".product-miniature .shopomnibus-price-notice.shopomnibus-listing")
        expect(omnibus_listings.first).to_be_visible(timeout=10_000)

        label_text = omnibus_listings.first.locator(".shopomnibus-label").inner_text().strip()
        assert "Najniższa cena z 30 dni" in label_text or "Najniższa cena" in label_text, (
            f"Expected listing label, got: {label_text}"
        )

        price_text = omnibus_listings.first.locator(".shopomnibus-price").inner_text().strip()
        parsed_price = parse_pln_amount(price_text)
        assert parsed_price > Decimal("0.00"), f"Expected valid positive price, got: {parsed_price}"


def test_omnibus_under_30_days_product(page: Page):
    """Sprawdza właściwą etykietę i kalkulację dla produktu oferowanego krócej niż 30 dni."""
    with step("Open product offered < 30 days (pandomu-5 - Zamek GAMA)"):
        open_source_product(page, 5, "GAMA")
        expect(page.locator("body#product")).to_be_attached(timeout=15_000)

    with step("Verify specialized label for < 30 days product"):
        omnibus_el = page.locator(".product-prices .shopomnibus-price-notice")
        expect(omnibus_el).to_be_visible()

        label_text = omnibus_el.locator(".shopomnibus-label").inner_text().strip()
        assert "Najniższa cena od wprowadzenia towaru" in label_text, (
            f"Expected under-30-days label, got: {label_text}"
        )

        price_text = omnibus_el.locator(".shopomnibus-price").inner_text().strip()
        lowest_amount = parse_pln_amount(price_text)
        assert lowest_amount == Decimal("59.99"), (
            f"Expected 59.99 PLN for pandomu-5, got: {lowest_amount}"
        )


def test_omnibus_fluctuating_prices(page: Page):
    """Sprawdza czy wyznaczane jest absolutne minimum z 30 dni przy wcześniejszych wahaniach ceny."""
    with step("Open fluctuating price scenario (pandomu-12 - Zamek ZN-160)"):
        open_source_product(page, 12, "ZN-160")
        expect(page.locator("body#product")).to_be_attached(timeout=15_000)

    with step("Verify minimum price from window is chosen (26.00 PLN rather than 28.00 PLN)"):
        omnibus_el = page.locator(".product-prices .shopomnibus-price-notice")
        expect(omnibus_el).to_be_visible()

        price_text = omnibus_el.locator(".shopomnibus-price").inner_text().strip()
        lowest_amount = parse_pln_amount(price_text)
        assert lowest_amount == Decimal("26.00"), (
            f"Expected 26.00 PLN minimum from window for pandomu-12, got: {lowest_amount}"
        )


def test_omnibus_successive_discounts(page: Page):
    """Sprawdza cenę odniesienia sprzed pierwszej obniżki przy kolejnych (sukcesywnych) obniżkach."""
    with step("Open successive discounts scenario (pandomu-541 - KLAMKA LIZA)"):
        open_source_product(page, 541, "LIZA")
        expect(page.locator("body#product")).to_be_attached(timeout=15_000)

    with step("Verify price before first discount in series is chosen (69.99 PLN rather than 59.99 PLN)"):
        omnibus_el = page.locator(".product-prices .shopomnibus-price-notice")
        expect(omnibus_el).to_be_visible()

        price_text = omnibus_el.locator(".shopomnibus-price").inner_text().strip()
        lowest_amount = parse_pln_amount(price_text)
        assert lowest_amount == Decimal("69.99"), (
            f"Expected 69.99 PLN before discount series for pandomu-541, got: {lowest_amount}"
        )


def test_omnibus_variant_switch(page: Page):
    """Sprawdza dynamiczną aktualizację ceny Omnibus po zmianie wariantu (kombinacji) na karcie."""
    with step("Open product with combinations (pandomu-944 - Wycieraczka z ramą)"):
        open_source_product(page, 944, "Wycieraczka")
        expect(page.locator("body#product")).to_be_attached(timeout=15_000)

    with step("Verify initial combination Omnibus price (144.74 PLN)"):
        omnibus_price = page.locator(".product-prices .shopomnibus-price-notice .shopomnibus-price")
        expect(omnibus_price).to_be_visible()
        initial_amount = parse_pln_amount(omnibus_price.inner_text().strip())
        assert initial_amount == Decimal("144.74"), (
            f"Expected initial combination price 144.74 PLN, got: {initial_amount}"
        )

    with step("Switch combination size to 35/50 and verify dynamic price update"):
        select_el = page.locator(".product-variants select").filter(
            has=page.locator("option", has_text="35/50")
        )
        expect(select_el).to_be_visible()
        select_el.select_option(label="35/50")

        # Czekamy na zaktualizowanie ceny w bloku cenowym (AJAX)
        expect(page.locator(".product-prices .shopomnibus-price-notice .shopomnibus-price")).to_contain_text(
            "132,44", timeout=10_000
        )
        updated_amount = parse_pln_amount(omnibus_price.inner_text().strip())
        assert updated_amount == Decimal("132.44"), (
            f"Expected updated combination price 132.44 PLN, got: {updated_amount}"
        )


def test_omnibus_hidden_on_regular_products(page: Page):
    """Sprawdza brak wyświetlania bloku Omnibus na produkcie w cenie regularnej (bez promocji)."""
    with step("Open product without any discount (pandomu-52)"):
        open_source_product(page, 52, "KASTEL")
        expect(page.locator("body#product")).to_be_attached(timeout=15_000)

    with step("Verify Omnibus notice is NOT rendered on non-promotional product"):
        omnibus_el = page.locator(".shopomnibus-price-notice")
        expect(omnibus_el).not_to_be_attached()
