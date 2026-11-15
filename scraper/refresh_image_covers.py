"""Refresh only source cover metadata; preserve all existing catalog fields."""

import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import logging
import os
from pathlib import Path
from urllib.parse import urldefrag, urlsplit
from urllib.request import Request, urlopen

from bs4 import BeautifulSoup

from catalog_files import read_products, save_json
from scraper_elements.scrap_id import scrap_id
from scraper_elements.scrap_images import scrap_cover_image


def fetch_source_soup(product):
    url = urldefrag(product["product_url"])[0]
    if urlsplit(url).scheme != "https" or urlsplit(url).hostname != "pandomu.com.pl":
        raise ValueError("Unexpected source product URL.")
    headers = {"User-Agent": "Mozilla/5.0", "Accept": "text/html"}
    cookie = os.environ.get("SCRAPER_CF_CLEARANCE")
    if cookie:
        headers["Cookie"] = "cf_clearance=" + cookie
    with urlopen(Request(url, headers=headers), timeout=30) as response:
        html = response.read().decode("utf-8")
    soup = BeautifulSoup(html, "html.parser")
    if str(scrap_id(soup)) != str(product["product_id"]):
        raise ValueError("Source product ID mismatch.")
    return soup


def fetch_cover(product):
    return scrap_cover_image(fetch_source_soup(product), product["images"])


def refresh_covers(products, fetch=fetch_cover, *, refresh=False, workers=2):
    pending = []
    for product in products:
        images = product.get("images", [])
        if len(images) <= 1:
            product["cover_image"] = images[0] if images else None
        elif refresh or product.get("cover_image") not in images:
            pending.append(product)
    errors = {}
    with ThreadPoolExecutor(max_workers=workers) as executor:
        futures = {executor.submit(fetch, product): product for product in pending}
        for index, future in enumerate(as_completed(futures), 1):
            product = futures[future]
            try:
                cover = future.result()
                if cover not in product["images"]:
                    raise ValueError("Cover is not in the saved gallery.")
                product["cover_image"] = cover
                logging.info("[%s/%s] Cover verified: %s", index, len(pending), product["product_id"])
            except Exception as error:
                errors[str(product["product_id"])] = str(error)
                logging.error("Cover verification failed: %s", product["product_id"])
    return errors


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--results-dir", type=Path, default=Path(__file__).resolve().parents[1] / "scraper-results")
    parser.add_argument("--product-id")
    parser.add_argument("--refresh", action="store_true")
    args = parser.parse_args()
    products = read_products(args.results_dir / "products.json")
    selected = [p for p in products if args.product_id is None or str(p["product_id"]) == args.product_id]
    if not selected:
        raise ValueError("No source products selected.")
    errors = refresh_covers(selected, refresh=args.refresh)
    # Replace only after requests finish; save_json uses atomic replacement.
    save_json(args.results_dir / "products.json", products)
    report = {"complete": not errors, "selected_products": len(selected), "errors": errors}
    save_json(args.results_dir / "image-cover-report.json", report)
    print(report)
    return 1 if errors else 0


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO, format="%(levelname)s: %(message)s")
    raise SystemExit(main())
