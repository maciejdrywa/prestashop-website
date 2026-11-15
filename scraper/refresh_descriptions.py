"""Migrate only short/full descriptions in an existing source catalog."""

import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import logging
from pathlib import Path

from catalog_files import read_products, save_json
from refresh_image_covers import fetch_source_soup
from scraper_elements.scrap_description import scrap_descriptions


def fetch_descriptions(product):
    return scrap_descriptions(fetch_source_soup(product))


def refresh_descriptions(products, fetch=fetch_descriptions, *, refresh=False):
    pending = [p for p in products if refresh or "description_short" not in p]
    errors = {}
    with ThreadPoolExecutor(max_workers=2) as executor:
        futures = {executor.submit(fetch, p): p for p in pending}
        for index, future in enumerate(as_completed(futures), 1):
            product = futures[future]
            try:
                fields = future.result()
                if not isinstance(fields.get("description"), str) or not fields["description"].strip() or not isinstance(fields.get("description_short"), str):
                    raise ValueError("Invalid source descriptions.")
                product.update({key: fields[key] for key in ("description", "description_short")})
                logging.info("[%s/%s] Descriptions verified: %s", index, len(pending), product["product_id"])
            except Exception as error:
                errors[str(product["product_id"])] = str(error)
                logging.error("Description verification failed: %s", product["product_id"])
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
        raise ValueError("No products selected.")
    errors = refresh_descriptions(selected, refresh=args.refresh)
    save_json(args.results_dir / "products.json", products)
    report = {"complete": not errors, "selected_products": len(selected), "errors": errors}
    save_json(args.results_dir / "description-report.json", report)
    print(report)
    return 1 if errors else 0


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO, format="%(levelname)s: %(message)s")
    raise SystemExit(main())
