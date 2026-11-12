import copy
import json
import unittest
from pathlib import Path
import tempfile
from types import SimpleNamespace

from bs4 import BeautifulSoup

from refresh_image_covers import refresh_covers
from scraper_elements.scrap_images import scrap_cover_image
from catalog_files import read_products, save_json
from main_scraper import scrape_catalog


class CoverTests(unittest.TestCase):
    images = [
        "https://pandomu.com.pl/1818-large_default/item.jpg",
        "https://pandomu.com.pl/1819-large_default/item.jpg",
    ]

    def test_data_cover_can_be_second_in_gallery(self):
        data = json.dumps({"cover": {"id_image": "1819"}})
        soup = BeautifulSoup("<div id='product-details'></div>", "html.parser")
        soup.div["data-product"] = data
        self.assertEqual(scrap_cover_image(soup, self.images), self.images[1])
        self.assertEqual(len(self.images), 2)

    def test_dom_cover_matches_another_resolution(self):
        soup = BeautifulSoup('<div class="product-cover"><img src="https://pandomu.com.pl/1819-medium_default/item.jpg"></div>', "html.parser")
        self.assertEqual(scrap_cover_image(soup, self.images), self.images[1])

    def test_meta_fallback_matches_source_id(self):
        soup = BeautifulSoup('<meta property="og:image" content="https://pandomu.com.pl/1819-home_default/item.jpg">', "html.parser")
        self.assertEqual(scrap_cover_image(soup, self.images), self.images[1])

    def test_missing_multi_image_cover_is_not_guessed(self):
        with self.assertRaises(ValueError):
            scrap_cover_image(BeautifulSoup("", "html.parser"), self.images)

    def test_single_or_missing_image_is_unambiguous(self):
        soup = BeautifulSoup("", "html.parser")
        self.assertEqual(scrap_cover_image(soup, self.images[:1]), self.images[0])
        self.assertIsNone(scrap_cover_image(soup, []))

    def test_refresh_preserves_order_prices_and_variants(self):
        products = [{"product_id": "661", "images": self.images.copy(), "price_netto": 4.463415, "variants": [{"price_modifier": 2}]}]
        before = copy.deepcopy(products)
        errors = refresh_covers(products, lambda product: self.images[1])
        self.assertEqual(errors, {})
        self.assertEqual(products[0]["cover_image"], self.images[1])
        del products[0]["cover_image"]
        self.assertEqual(products, before)

    def test_failed_refresh_preserves_previous_metadata(self):
        products = [{"product_id": "661", "images": self.images.copy(), "cover_image": self.images[1]}]
        before = copy.deepcopy(products)
        with self.assertLogs(level="ERROR"):
            errors = refresh_covers(products, lambda product: "not in gallery", refresh=True)
        self.assertIn("661", errors)
        self.assertEqual(products, before)

    def test_main_resume_updates_cover_without_repricing_or_reordering(self):
        product = {"product_id": "661", "name": "Item", "images": self.images.copy(),
                   "variants": [], "price_brutto": 5.49, "price_netto": 4.463415,
                   "breadcrumbs": ["Strona glowna", "Root", "Item"]}
        entry = {"product_url": "https://pandomu.com.pl/test", "category_paths": [["Root"]],
                 "category_source_urls": ["https://pandomu.com.pl/root"]}
        html = '<body class="product-id-661"><div class="product-cover"><img src="' + self.images[1] + '"></div></body>'
        calls = []
        def fetch(url):
            calls.append(url)
            return html
        crawler = SimpleNamespace(discover_catalog=lambda: {"products": {"661": entry}, "categories": []}, fetch_page=fetch)
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder)
            save_json(path / "products.json", [product])
            report = scrape_catalog(crawler, path)
            updated = read_products(path / "products.json")[0]
            self.assertEqual(updated["cover_image"], self.images[1])
            self.assertEqual(updated["images"], product["images"])
            self.assertEqual(updated["price_netto"], product["price_netto"])
            self.assertEqual(updated["variants"], product["variants"])
            self.assertTrue(report["source_covers_complete"])
            self.assertEqual(len(calls), 1)


if __name__ == "__main__":
    unittest.main()
