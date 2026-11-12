import html as html_module
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

from Scrapping_products.variant_prices import collect_variants, decimal_price, variant_from_data
from Scrapping_products.store_crawler import store_crawler
from catalog_files import assign_categories, read_products, save_json
from main_scraper import scrape_catalog


def data(variant_id=101, selection=None, net="100", gross="123", product_id=5):
    selection = selection or {"2": "1", "3": "10"}
    labels = {"1": "red", "2": "blue", "3": "green", "10": "small", "20": "large"}
    return {
        "id_product": product_id,
        "id_product_attribute": variant_id,
        "price_tax_exc": net,
        "price_amount": gross,
        "attributes": {
            group: {"id_attribute": value, "group": "Color" if group == "2" else "Size", "name": labels[value]}
            for group, value in selection.items()
        },
    }


def details(product_data):
    encoded = html_module.escape(json.dumps(product_data), quote=True)
    return f'<div id="product-details" data-product="{encoded}"></div>'


def controls(colors=("1", "2"), sizes=("10", "20")):
    return '<div class="product-variants">' + ''.join(
        f'<select name="group[{group}]">' + ''.join(f'<option value="{value}">{value}</option>' for value in values) + '</select>'
        for group, values in (("2", colors), ("3", sizes))
    ) + '</div>'


def source():
    return SimpleNamespace(product_id="5", name="Product", price_netto=100, variants=[])


class VariantPriceTests(unittest.TestCase):
    def test_positive_negative_and_zero_net_modifiers(self):
        for net, gross, expected in (("110", "135.3", 10), ("95", "116.85", -5), ("100", "123", 0)):
            with self.subTest(net=net):
                variant = variant_from_data(data(net=net, gross=gross), "Product", "100")
                self.assertEqual(variant.price_modifier, expected)
                self.assertEqual(variant.price_brutto, float(gross))
                self.assertEqual(variant.price_netto, float(net))

    def test_decimal_precision_is_preserved_to_six_places(self):
        variant = variant_from_data(data(net="48.780488", gross="60"), "Product", "48.772358")
        self.assertEqual(variant.price_modifier, 0.008130)

    def test_missing_or_invalid_prices_fail_instead_of_becoming_zero(self):
        for value in (None, "", "NaN", "Infinity", "-1", True):
            with self.subTest(value=value), self.assertRaises(ValueError):
                decimal_price(value, "price")
        invalid = data()
        del invalid["price_tax_exc"]
        with self.assertRaises(ValueError):
            variant_from_data(invalid, "Product", 100)

    def test_real_combinations_with_nonadditive_prices_and_invalid_selection(self):
        initial = data()
        html = details(initial) + controls()
        responses = {
            ("1", "20"): data(102, {"2": "1", "3": "20"}, "110", "135.3"),
            ("2", "10"): data(103, {"2": "2", "3": "10"}, "95", "116.85"),
            # Source substitutes its default for a nonexistent blue/large pair.
            ("2", "20"): initial,
        }
        calls = []

        def refresh(selection):
            calls.append(selection)
            return {"product_details": details(responses[(selection["2"], selection["3"])]), "product_variants": controls()}

        variants = collect_variants(html, source(), refresh)
        self.assertEqual([variant.variant_id for variant in variants], ["101", "102", "103"])
        self.assertEqual([variant.price_modifier for variant in variants], [0, 10, -5])
        self.assertTrue(all(len(variant.attributes) == 2 for variant in variants))
        self.assertEqual(len(calls), 3)

    def test_newly_exposed_options_are_also_checked(self):
        initial = data(selection={"2": "1", "3": "10"})
        html = details(initial) + controls(sizes=("10",))

        def refresh(selection):
            variant = data(100 + int(selection["2"]), selection)
            return {"product_details": details(variant), "product_variants": controls(colors=("1", "2", "3"), sizes=("10",))}

        variants = collect_variants(html, source(), refresh)
        self.assertEqual(len(variants), 3)
        self.assertIn("green", [variant.attributes["Color"] for variant in variants])

    def test_old_default_combination_with_different_groups_is_not_a_form_choice(self):
        # Regression for source product 631: URL selects an obsolete size,
        # whereas the current form exposes only color choices.
        initial = data(999, {"3": "10"})
        form = '<div class="product-variants"><select name="group[2]"><option value="1">red</option><option value="2">blue</option></select></div>'
        calls = []

        def refresh(selection):
            calls.append(selection)
            return {"product_details": details(data(100 + int(selection["2"]), selection, "110", "135.3")), "product_variants": form}

        with self.assertLogs(level="WARNING"):
            variants = collect_variants(details(initial) + form, source(), refresh)
        self.assertEqual(len(calls), 2)
        self.assertEqual([v.variant_id for v in variants], ["101", "102"])
        self.assertTrue(all(set(v.attributes) == {"Color"} for v in variants))
        self.assertNotIn("999", [v.variant_id for v in variants])

    def test_fixed_attributes_outside_form_are_preserved(self):
        initial = data()
        form = '<div class="product-variants"><select name="group[2]"><option value="1">red</option><option value="2">blue</option></select></div>'
        variants = collect_variants(
            details(initial) + form, source(),
            lambda selection: {"product_details": details(data(102, {**selection, "3": "10"}))},
        )
        self.assertEqual(len(variants), 2)
        self.assertTrue(all(v.attributes["Size"] == "small" for v in variants))

    def test_wrong_product_and_missing_response_prices_are_errors(self):
        html = details(data()) + controls(colors=("1",), sizes=("10", "20"))
        for response_data in (data(102, product_id=99), data(102, {"2": "1", "3": "20"}, net=None)):
            with self.subTest(response=response_data), self.assertRaises(ValueError):
                collect_variants(html, source(), lambda selection: {"product_details": details(response_data)})

    def test_requests_target_product_controller_never_cart(self):
        initial = data()
        html = details(initial) + controls(colors=("1",), sizes=("10", "20"))
        html += '<form id="add-to-cart-or-refresh" action="https://pandomu.com.pl/koszyk"><input type="hidden" name="add" value="1"></form>'
        returned = data(102, {"2": "1", "3": "20"}, "110", "135.3")
        calls = []

        def post(endpoint, **kwargs):
            calls.append((endpoint, kwargs))
            return SimpleNamespace(ok=True, json=lambda: {"product_details": details(returned)})

        crawler = store_crawler.__new__(store_crawler)
        crawler.context = SimpleNamespace(request=SimpleNamespace(post=post))
        product = source()
        product.product_url = "https://pandomu.com.pl/category/5-item.html#/color"
        with patch("Scrapping_products.store_crawler.time.sleep"):
            variants = crawler.fetch_variants(html, product)
        self.assertEqual(len(variants), 2)
        self.assertEqual(calls[0][0], "https://pandomu.com.pl/category/5-item.html")
        self.assertEqual(calls[0][1]["form"]["action"], "refresh")
        self.assertNotIn("add", calls[0][1]["form"])

    def test_legacy_breadcrumbs_are_removed(self):
        assigned = assign_categories(
            {"product_id": "5", "name": "Product", "breadcrumbs": ["Home", "Old", "Product"], "source_breadcrumbs": ["Home", "Product"]},
            {"category_paths": [["Root", "Child"]], "category_source_urls": ["https://pandomu.com.pl/11-child"]},
        )
        self.assertNotIn("source_breadcrumbs", assigned)
        self.assertEqual(assigned["breadcrumbs"], ["Strona główna", "Root", "Child", "Product"])

    def test_old_zero_modifiers_are_refreshed_without_refresh_flag(self):
        entry = {"product_url": "https://pandomu.com.pl/5-item.html", "category_paths": [["Root"]], "category_source_urls": ["https://pandomu.com.pl/10-root"]}

        class FakeCrawler:
            def discover_catalog(self):
                return {"categories": [], "products": {"5": entry}}

            def fetch_page(self, url):
                return "html"

            def fetch_variants(self, html, parsed):
                return [variant_from_data(data(net="110", gross="135.3"), "Product", 100)]

        old = {"product_id": "5", "name": "Keep name", "description": "Keep description", "description_short": "", "breadcrumbs": [], "source_breadcrumbs": [], "variants": [{"price_modifier": 0}], "images": ["image"], "price_netto": 90}
        parsed = SimpleNamespace(product_id="5", price_netto=100, price_brutto=123, vat_rate=23, stock=None)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            save_json(path / "products.json", [old])
            with patch("main_scraper.parse_product", return_value=parsed):
                scrape_catalog(FakeCrawler(), path)
            current = read_products(path / "products.json")[0]
            self.assertEqual(current["variants"][0]["price_modifier"], 10)
            self.assertEqual(current["price_netto"], 100)
            self.assertEqual(current["description"], old["description"])
            self.assertNotIn("source_breadcrumbs", current)

    def test_failed_variant_request_preserves_old_prices_and_reports_failure(self):
        entry = {"product_url": "https://pandomu.com.pl/5-item.html", "category_paths": [["Root"]], "category_source_urls": ["https://pandomu.com.pl/10-root"]}

        class FakeCrawler:
            def discover_catalog(self):
                return {"categories": [], "products": {"5": entry}}

            def fetch_page(self, url):
                return "html"

            def fetch_variants(self, html, parsed):
                raise RuntimeError("HTTP 503")

        previous = {"product_id": "5", "name": "Product", "breadcrumbs": [], "price_netto": 90, "variants": [{"price_modifier": 0}], "images": ["image"]}
        parsed = SimpleNamespace(product_id="5", price_netto=100)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            save_json(path / "products.json", [previous])
            with patch("main_scraper.parse_product", return_value=parsed), self.assertLogs(level="ERROR"):
                with self.assertRaises(RuntimeError):
                    scrape_catalog(FakeCrawler(), path)
            current = read_products(path / "products.json")[0]
            self.assertEqual(current["variants"], previous["variants"])
            self.assertEqual(current["price_netto"], 90)
            report = json.loads((path / "scrape-report.json").read_text(encoding="utf-8"))
            self.assertFalse(report["variant_prices_complete"])
            self.assertEqual(report["products_with_unverified_variant_prices"], ["5"])
            self.assertEqual(report["product_errors"], {"5": "HTTP 503"})


if __name__ == "__main__":
    unittest.main()
