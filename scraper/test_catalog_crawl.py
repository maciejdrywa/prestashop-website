"""Offline regressions for category hierarchy, completeness and resumed scraping."""

import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from Scrapping_products.catalog_discovery import discover_catalog, scan_category
from Scrapping_products.parse_category import parse_category, parse_navigation
from catalog_files import assign_categories, read_products, save_json
from main_scraper import scrape_catalog
from Scrapping_products.parse_product import parse_product
from bs4 import BeautifulSoup


BASE = "https://pandomu.com.pl/"
ROOT = BASE + "10-root"
CHILD = BASE + "11-child"
LEAF = BASE + "12-leaf"
OTHER = BASE + "20-other"
RECOMMENDED = BASE + "81-produkty-polecane"


def listing(ids=(), children=(), next_url=None, total=None):
    cards = "".join(
        f'<a class="thumbnail product-thumbnail" href="/strona-glowna/{id}-7-item.html#/color">item</a>'
        for id in ids
    )
    links = "".join(
        f'<li><div class="subcategory-image"><a title="{name}" href="{url}"></a></div>'
        f'<a class="subcategory-name" href="{url}">truncated...</a></li>'
        for name, url in children
    )
    next_link = f'<a rel="next js-search-link" href="{next_url}">next</a>' if next_url else ""
    total = len(ids) if total is None else total
    return (
        f'<div id="subcategories"><ul>{links}</ul></div><section id="products">'
        f'<div class="total-products">Jest {total} produktów.</div>{cards}{next_link}</section>'
    )


class CatalogTests(unittest.TestCase):
    def test_navigation_preserves_parents_and_ignores_icons(self):
        html = f'''<ul id="top-menu"><li class="category"><a href="{ROOT}">
        <span>expand_more</span>Root</a><div><ul><li class="category">
        <a href="{CHILD}">Child</a></li></ul></div></li></ul>'''
        paths = parse_navigation(html, BASE)
        self.assertEqual([[link.name for link in path] for path in paths], [["Root"], ["Root", "Child"]])

    def test_relative_pagination_full_child_name_and_no_filters(self):
        html = listing([5], [("Full child name", "/11-child")], "?page=2", 2)
        html += '<a href="/99-fake?q=filter">filter</a>'
        page = parse_category(html, ROOT)
        self.assertEqual(page.next_page_url, ROOT + "?page=2")
        self.assertEqual(page.product_urls, [BASE + "strona-glowna/5-7-item.html"])
        self.assertEqual(page.subcategories[0].name, "Full child name")
        self.assertEqual(len(page.subcategories), 1)

    def test_tree_pagination_multiple_branches_and_deepest_memberships(self):
        home = f'''<ul id="top-menu"><li class="category"><a href="{ROOT}">Root</a>
        <ul><li class="category"><a href="{CHILD}">Child</a></li></ul></li>
        <li class="category"><a href="{OTHER}">Other</a></li></ul>'''
        pages = {
            BASE: home,
            ROOT: listing([5], next_url="?page=2", total=2),
            ROOT + "?page=2": listing([6], total=2),
            CHILD: listing([5], [("Leaf", LEAF)]),
            LEAF: listing([5]),
            OTHER: listing([5]),
            RECOMMENDED: listing(),
        }
        calls = []

        def fetch(url):
            calls.append(url)
            return pages[url]

        catalog = discover_catalog(fetch)
        self.assertEqual(set(catalog["products"]), {"5", "6"})
        self.assertEqual(catalog["products"]["5"]["category_paths"], [["Root", "Child", "Leaf"], ["Other"]])
        self.assertEqual(catalog["products"]["6"]["category_paths"], [["Root"]])
        self.assertEqual(calls.count(CHILD), 1)
        self.assertEqual(len(catalog["categories"]), 5)

    def test_failed_page_and_missing_products_do_not_count_as_success(self):
        with self.assertRaisesRegex(RuntimeError, "pobrać"):
            scan_category(lambda url: None, ROOT)
        with self.assertRaisesRegex(RuntimeError, "Niepełna"):
            scan_category(lambda url: listing([5], total=2), ROOT)

    def test_pagination_cycle_is_rejected(self):
        with self.assertRaisesRegex(RuntimeError, "Pętla"):
            scan_category(lambda url: listing([5], next_url=ROOT), ROOT)

    def test_category_tree_cycle_is_rejected(self):
        pages = {
            BASE: f'<ul id="top-menu"><li class="category"><a href="{ROOT}">Root</a></li></ul>',
            ROOT: listing([5], [("Child", CHILD)]),
            CHILD: listing([5], [("Root", ROOT)]),
            RECOMMENDED: listing(),
        }
        with self.assertRaisesRegex(RuntimeError, "Cykl"):
            discover_catalog(pages.__getitem__)

    def test_original_category_is_preferred_when_still_valid(self):
        product = {"product_id": "5", "name": "Test", "breadcrumbs": ["Home", "Other", "Test"]}
        discovered = {
            "category_paths": [["Root", "Child"], ["Other"]],
            "category_source_urls": [CHILD, OTHER],
        }
        assigned = assign_categories(product, discovered)
        self.assertEqual(assigned["breadcrumbs"], ["Strona główna", "Other", "Test"])
        self.assertEqual(len(assigned["category_paths"]), 2)

    def test_category_failure_replaces_previous_success_report(self):
        class BrokenCrawler:
            def discover_catalog(self):
                raise RuntimeError("category unavailable")

        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            save_json(path / "scrape-report.json", {"complete": True})
            with self.assertRaisesRegex(RuntimeError, "unavailable"):
                scrape_catalog(BrokenCrawler(), path)
            report = json.loads((path / "scrape-report.json").read_text(encoding="utf-8"))
            self.assertFalse(report["complete"])
            self.assertEqual(report["stage"], "category_discovery")

    def test_resume_updates_categories_without_fetching_or_losing_modifiers(self):
        saved = {
            "product_id": "5", "name": "Test", "breadcrumbs": ["Strona główna", "Test"],
            "variants": [{"price_modifier": 12.5, "price_netto": 62.5, "price_brutto": 76.88}],
        }
        discovered = {
            "product_id": "5", "product_url": BASE + "5-item.html",
            "category_paths": [["Root", "Child"]], "category_source_urls": [CHILD],
        }

        class FakeCrawler:
            def discover_catalog(self):
                return {"categories": [], "products": {"5": discovered}}

            def fetch_page(self, url):
                raise AssertionError("Existing details must not be fetched again")

        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            save_json(path / "products.json", [saved])
            scrape_catalog(FakeCrawler(), path)
            first = read_products(path / "products.json")
            scrape_catalog(FakeCrawler(), path)
            self.assertEqual(first, read_products(path / "products.json"))
            self.assertEqual(first[0]["breadcrumbs"], ["Strona główna", "Root", "Child", "Test"])
            self.assertNotIn("source_breadcrumbs", first[0])
            self.assertEqual(first[0]["variants"][0]["price_modifier"], 12.5)

    def test_new_product_saved_once_and_failed_refresh_reported(self):
        entry = {"product_url": BASE + "5-item.html", "category_paths": [["Root"]], "category_source_urls": [ROOT]}
        new_product = {"product_id": "5", "name": "New", "breadcrumbs": [], "images": []}

        class Parsed:
            product_id = "5"

            def model_dump(self):
                return new_product

        class FakeCrawler:
            def discover_catalog(self):
                return {"categories": [], "products": {"5": entry}}

            def fetch_page(self, url):
                return "html"

            def fetch_variants(self, html, parsed):
                return []

        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            with patch("main_scraper.parse_product", return_value=Parsed()):
                scrape_catalog(FakeCrawler(), path)
            self.assertEqual(len(read_products(path / "products.json")), 1)
            initial_report = json.loads((path / "scrape-report.json").read_text(encoding="utf-8"))
            self.assertTrue(initial_report["complete"])
            self.assertFalse(initial_report["source_images_complete"])
            self.assertEqual(initial_report["products_without_images"], ["5"])
            with patch("main_scraper.parse_product", return_value=None):
                with self.assertRaisesRegex(RuntimeError, "Niepełne"):
                    scrape_catalog(FakeCrawler(), path, refresh=True)
            report = json.loads((path / "scrape-report.json").read_text(encoding="utf-8"))
            self.assertFalse(report["complete"])
            self.assertEqual(report["failed_product_ids"], ["5"])
            self.assertEqual(len(read_products(path / "products.json")), 1)

    def test_invalid_existing_file_is_not_silently_replaced(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "products.json"
            path.write_text("broken", encoding="utf-8")
            with self.assertRaises(json.JSONDecodeError):
                read_products(path)

    def test_product_without_source_photos_is_not_dropped(self):
        html = (Path(__file__).parent / "html_files_and_robots" / "Test1.html").read_text(encoding="utf-8")
        soup = BeautifulSoup(html, "html.parser")
        for gallery in soup.select("ul.product-images"):
            gallery.decompose()
        with self.assertLogs(level="WARNING"):
            product = parse_product(str(soup), BASE + "359-item.html")
        self.assertIsNotNone(product)
        self.assertEqual(product.product_id, "359")
        self.assertEqual(product.images, [])


if __name__ == "__main__":
    unittest.main()
