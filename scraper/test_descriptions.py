import copy
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest

from bs4 import BeautifulSoup

from catalog_files import read_products, save_json
from main_scraper import scrape_catalog
from refresh_descriptions import refresh_descriptions
from scraper_elements.scrap_description import scrap_descriptions


class DescriptionTests(unittest.TestCase):
    def parse(self, short="", full=""):
        return scrap_descriptions(BeautifulSoup(
            '<div id="product-description-short-800">' + short
            + '</div><div id="description"><div class="product-description">'
            + full + '</div></div>', "html.parser"))

    def test_two_distinct_descriptions_are_preserved(self):
        self.assertEqual(self.parse("<p>Short</p>", "<p>Full</p>"),
                         {"description": "<p>Full</p>", "description_short": "<p>Short</p>"})

    def test_identical_descriptions_stay_in_both_fields(self):
        self.assertEqual(self.parse("<p>Same</p>", "<p>Same</p>"),
                         {"description": "<p>Same</p>", "description_short": "<p>Same</p>"})

    def test_only_full_description_leaves_summary_empty(self):
        self.assertEqual(self.parse("", "<p>Full</p>"),
                         {"description": "<p>Full</p>", "description_short": ""})

    def test_only_short_description_is_moved_to_bottom(self):
        self.assertEqual(self.parse("<p>Only text</p>", ""),
                         {"description": "<p>Only text</p>", "description_short": ""})

    def test_empty_html_is_not_an_existing_summary(self):
        self.assertEqual(self.parse("<p>&nbsp;<br></p>", "<p>Full</p>")["description_short"], "")

    def test_missing_both_descriptions_is_reported(self):
        with self.assertRaises(ValueError):
            self.parse()

    def test_refresh_only_changes_description_fields(self):
        products = [{"product_id": "800", "description": "Old", "price_brutto": 17.87,
                     "images": ["image"], "cover_image": "image", "variants": [{"price_modifier": 5}]}]
        before = copy.deepcopy(products)
        errors = refresh_descriptions(products, lambda product: {"description": "<p>Full</p>", "description_short": "<p>Short</p>"})
        self.assertEqual(errors, {})
        for key in before[0]:
            if key != "description":
                self.assertEqual(products[0][key], before[0][key])

    def test_main_resume_fills_descriptions_without_repricing(self):
        product = {"product_id": "800", "name": "Item", "description": "Old combined text",
                   "images": ["image"], "variants": [], "price_brutto": 17.87}
        entry = {"product_url": "https://pandomu.com.pl/item", "category_paths": [["Root"]],
                 "category_source_urls": ["https://pandomu.com.pl/root"]}
        html = '<body class="product-id-800"><div id="product-description-short-800"><p>Short</p></div><div id="description"><p>Full</p></div></body>'
        crawler = SimpleNamespace(discover_catalog=lambda: {"products": {"800": entry}, "categories": []},
                                  fetch_page=lambda url: html)
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder)
            save_json(path / "products.json", [product])
            scrape_catalog(crawler, path)
            result = read_products(path / "products.json")[0]
            self.assertEqual(result["description_short"], "<p>Short</p>")
            self.assertEqual(result["description"], "<p>Full</p>")
            self.assertEqual(result["price_brutto"], product["price_brutto"])
            self.assertEqual(result["variants"], [])


if __name__ == "__main__":
    unittest.main()
