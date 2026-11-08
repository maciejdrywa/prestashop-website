"""Scrape the complete reachable category tree and resume products by source ID."""

import argparse
import logging
import os
from pathlib import Path

from Scrapping_products.store_crawler import store_crawler
from Scrapping_products.parse_product import parse_product
from catalog_files import assign_categories, read_products, save_json


RESULTS_DIR = Path(__file__).resolve().parent.parent / "scraper-results"
RESULTS_FILE = RESULTS_DIR / "products.json"

# Keep the original public helper names for callers.
load_existing_products = read_products
save_products = save_json


def scrape_catalog(crawler, results_dir: Path, *, refresh: bool = False) -> dict:
    results_file = results_dir / "products.json"
    saved = {str(product["product_id"]): product for product in read_products(results_file)}
    report_file = results_dir / "scrape-report.json"
    save_json(report_file, {"complete": False, "stage": "category_discovery"})
    try:
        catalog = crawler.discover_catalog()
    except Exception as error:
        save_json(report_file, {
            "complete": False,
            "stage": "category_discovery",
            "error": str(error),
        })
        raise
    discovered = catalog["products"]
    save_json(results_dir / "categories.json", catalog["categories"])
    save_json(report_file, {
        "complete": False,
        "stage": "product_details",
        "discovered_products": len(discovered),
    })

    # Update memberships even when existing product details do not need fetching.
    for product_id in saved.keys() & discovered.keys():
        saved[product_id] = assign_categories(saved[product_id], discovered[product_id])
    if saved:
        save_json(results_file, list(saved.values()))

    failed = []
    fetched = 0
    reused = 0
    for index, (product_id, entry) in enumerate(sorted(discovered.items()), start=1):
        logging.info("Produkt %s/%s, ID %s", index, len(discovered), product_id)
        if product_id in saved and not refresh:
            reused += 1
            continue
        html = crawler.fetch_page(entry["product_url"])
        parsed = parse_product(html, entry["product_url"]) if html else None
        if parsed is None or str(parsed.product_id) != product_id:
            logging.error("Nie udało się pobrać poprawnych danych produktu %s", product_id)
            failed.append(product_id)
            continue
        saved[product_id] = assign_categories(parsed.model_dump(), entry)
        fetched += 1
        save_json(results_file, list(saved.values()))

    undiscovered = sorted(saved.keys() - discovered.keys())
    without_images = sorted(
        product_id for product_id in discovered.keys() & saved.keys()
        if not saved[product_id].get("images")
    )
    report = {
        "complete": not failed and not undiscovered,
        "source_images_complete": not without_images,
        "categories": len(catalog["categories"]),
        "discovered_products": len(discovered),
        "fetched_products": fetched,
        "reused_products": reused,
        "failed_product_ids": failed,
        "previous_products_not_discovered": undiscovered,
        "products_without_images": without_images,
    }
    save_json(results_dir / "scrape-report.json", report)
    logging.info("Podsumowanie: %s", report)
    if without_images:
        logging.warning(
            "Źródło nie udostępnia zdjęć dla produktów %s. Wyeksportowano je z images=[]; "
            "wymagają rozstrzygnięcia przed importem sklepu.", ", ".join(without_images)
        )
    if not report["complete"]:
        raise RuntimeError(
            "Niepełne pobieranie. Sprawdź scrape-report.json; zachowano wcześniejsze dane."
        )
    return report


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--refresh", action="store_true", help="Pobierz ponownie również szczegóły zapisanych produktów.")
    parser.add_argument("--headless", action="store_true", help="Uruchom bez widocznego okna przeglądarki.")
    parser.add_argument("--output-dir", type=Path, default=RESULTS_DIR)
    args = parser.parse_args()
    crawler = store_crawler(
        cookies=os.environ.get("SCRAPER_CF_CLEARANCE", ""),
        headless=args.headless,
    )
    try:
        scrape_catalog(crawler, args.output_dir, refresh=args.refresh)
    finally:
        crawler.close()
    return 0


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO, format="%(asctime)s - %(levelname)s - %(message)s")
    try:
        raise SystemExit(main())
    except Exception:
        logging.exception("Scrapowanie zakończyło się błędem.")
        raise SystemExit(1)
