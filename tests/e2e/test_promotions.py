"""D3.1: Weryfikacja wyświetlania i naliczania promocji cenowych w sklepie."""

import re
from decimal import Decimal

from helpers.cart import wait_for_shop_scripts
from helpers.catalog import open_source_product
from helpers.money import parse_pln_amount
from helpers.steps import step
from playwright.sync_api import Page, expect


def test_promotional_product_display_and_cart(page: Page):
    """Sprawdza wyświetlanie ceny pierwotnej, rabatu i ceny promocyjnej oraz naliczanie w koszyku."""
    with step("Open promotional product page"):
        # Referencja źródłowa pozostaje stała po ponownym imporcie katalogu.
        product_id = open_source_product(page, 12, "ZN-160")
        expect(page.locator("body#product")).to_be_attached(timeout=15_000)

    with step(
        "Verify crossed-out regular price, discount badge and promotional price on product sheet"
    ):
        regular_price_el = page.locator(".product-discount .regular-price").first
        expect(
            regular_price_el, "Regular price should be visible on promotional product"
        ).to_be_visible()
        regular_text = regular_price_el.inner_text().strip()
        regular_amount = parse_pln_amount(regular_text)
        assert regular_amount == Decimal("28.00"), (
            f"Expected 28.00 PLN regular price, got {regular_amount}"
        )

        current_price_el = page.locator(
            ".current-price .current-price-value, .current-price [content]"
        ).first
        expect(current_price_el, "Promotional price should be visible").to_be_visible()
        current_amount = parse_pln_amount(current_price_el.inner_text().strip())
        assert current_amount == Decimal("25.20"), (
            f"Expected 25.20 PLN promo price, got {current_amount}"
        )

        discount_badge = page.locator(
            ".current-price .discount, .discount-percentage, .discount-amount"
        ).first
        expect(discount_badge, "Discount badge should be visible").to_be_visible()
        assert re.search(r"10%", discount_badge.inner_text()), (
            "Badge should indicate 10% discount"
        )

    with step("Add 2 units of promotional product to cart"):
        wait_for_shop_scripts(page)
        qty_input = page.locator("#quantity_wanted")
        qty_input.fill("2")
        page.locator("button.add-to-cart").click()
        modal = page.locator("#blockcart-modal")
        expect(modal).to_be_visible(timeout=15_000)

    with step("Verify cart modal subtotal includes discount"):
        modal_unit = modal.locator(".modal-body .product-price").first
        expect(modal_unit).to_be_visible()
        unit_amount = parse_pln_amount(modal_unit.inner_text().strip())
        assert unit_amount == Decimal("25.20"), (
            f"Modal unit price should be 25.20 PLN, got {unit_amount}"
        )

        modal_subtotal = modal.locator(".cart-content .subtotal.value").first
        expect(modal_subtotal).to_be_visible()
        subtotal_amount = parse_pln_amount(modal_subtotal.inner_text().strip())
        expected_line = Decimal("50.40")  # 2 * 25.20
        assert subtotal_amount == expected_line, (
            f"Modal products subtotal should be {expected_line} PLN, got {subtotal_amount}"
        )

    with step("Go to cart and verify line items and totals"):
        page.goto("/index.php?controller=cart&action=show")
        expect(page.locator("body#cart")).to_be_attached()

        cart_item = page.locator(
            f'.cart-item:has(input.js-cart-line-product-quantity[data-product-id="{product_id}"])'
        ).first
        expect(cart_item).to_be_visible()

        # Regular price and promo price in cart line
        price_in_cart = cart_item.locator(
            ".current-price .price, .product-price .price"
        ).first
        expect(price_in_cart).to_be_visible()
        cart_line_unit = parse_pln_amount(price_in_cart.inner_text().strip())
        assert cart_line_unit == Decimal("25.20"), (
            f"Cart unit price should be 25.20 PLN, got {cart_line_unit}"
        )

        # Subtotal products in summary
        summary_products = page.locator("#cart-subtotal-products .value").first
        expect(summary_products).to_be_visible()
        subtotal_amount = parse_pln_amount(summary_products.inner_text().strip())
        assert subtotal_amount == expected_line, (
            f"Cart subtotal should be {expected_line} PLN, got {subtotal_amount}"
        )


def test_promotional_product_listing_card(page: Page):
    """Sprawdza obecność miniatury z ceną promocyjną na liście wyszukiwania."""
    with step("Search for 'klamka' to find promotional listings"):
        page.goto("/szukaj?s=klamka")
        expect(page.locator("body#search")).to_be_attached(timeout=15_000)

        # Powinno być co najmniej jedno ogłoszenie promocyjne
        promo_card = page.locator(
            '.product-miniature:has(.regular-price):has([class*="discount"])'
        ).first
        expect(
            promo_card,
            "At least one promotional miniature should appear in search results",
        ).to_be_visible(timeout=15_000)

        reg_price = promo_card.locator(".regular-price").first
        cur_price = promo_card.locator(".price").first
        badge = promo_card.locator('[class*="discount"]').first

        expect(reg_price).to_be_visible()
        expect(cur_price).to_be_visible()
        expect(badge).to_be_visible()

        reg_val = parse_pln_amount(reg_price.inner_text())
        cur_val = parse_pln_amount(cur_price.inner_text())
        assert cur_val < reg_val, (
            f"Promotional price {cur_val} must be lower than regular {reg_val}"
        )
