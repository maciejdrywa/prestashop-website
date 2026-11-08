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


def needs_variant_refresh(product: dict) -> bool:
    """Legacy exports contain option IDs and unverified zero modifiers."""
    return any(
        variant.get("price_netto") is None or variant.get("price_brutto") is None
        for variant in product.get("variants", [])
    )


def scrape_catalog(crawler, results_dir: Path, *, refresh: bool = False, refresh_variants: bool = False) -> dict:
    results_file = results_dir / "products.json"
    saved = {str(product["product_id"]): product for product in read_products(results_file)}
    for product in saved.values():
        product.pop("source_breadcrumbs", None)
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
    product_errors = {}
    fetched = 0
    reused = 0
    for index, (product_id, entry) in enumerate(sorted(discovered.items()), start=1):
        logging.info("Produkt %s/%s, ID %s", index, len(discovered), product_id)
        previous = saved.get(product_id)
        refresh_prices = previous is not None and (
            needs_variant_refresh(previous)
            or (refresh_variants and bool(previous.get("variants")))
        )
        if previous is not None and not refresh and not refresh_prices:
            reused += 1
            continue
        failure_reason = None
        try:
            html = crawler.fetch_page(entry["product_url"])
            parsed = parse_product(html, entry["product_url"]) if html else None
            if parsed is not None:
                parsed.variants = crawler.fetch_variants(html, parsed)
            else:
                failure_reason = "Nie udało się pobrać lub sparsować strony produktu; szczegóły w logu."
        except Exception as error:
            logging.exception("Nie udało się zweryfikować wariantów produktu %s", product_id)
            failure_reason = str(error)
            parsed = None
        if parsed is None or str(parsed.product_id) != product_id:
            logging.error("Nie udało się pobrać poprawnych danych produktu %s", product_id)
            failed.append(product_id)
            product_errors[product_id] = failure_reason or "Źródło zwróciło inne ID produktu."
            continue
        if previous is not None and not refresh and refresh_prices:
            # Repricing must not combine fresh modifiers with a stale base price.
            updated = {
                **previous,
                "price_netto": parsed.price_netto,
                "price_brutto": parsed.price_brutto,
                "vat_rate": parsed.vat_rate,
                "variants": [variant.model_dump() for variant in parsed.variants],
                "stock": parsed.stock,
            }
        else:
            updated = parsed.model_dump()
        saved[product_id] = assign_categories(updated, entry)
        fetched += 1
        save_json(results_file, list(saved.values()))

    undiscovered = sorted(saved.keys() - discovered.keys())
    without_images = sorted(
        product_id for product_id in discovered.keys() & saved.keys()
        if not saved[product_id].get("images")
    )
    unverified_prices = sorted(
        product_id for product_id in discovered.keys() & saved.keys()
        if needs_variant_refresh(saved[product_id])
    )
    report = {
        "complete": not failed and not undiscovered,
        "source_images_complete": not without_images,
        "variant_prices_complete": not failed and not unverified_prices,
        "categories": len(catalog["categories"]),
        "discovered_products": len(discovered),
        "fetched_products": fetched,
        "reused_products": reused,
        "failed_product_ids": failed,
        "product_errors": product_errors,
        "previous_products_not_discovered": undiscovered,
        "products_without_images": without_images,
        "products_with_unverified_variant_prices": unverified_prices,
    }
    save_json(results_dir / "scrape-report.json", report)
    logging.info("Podsumowanie: %s", report)
    if without_images:
        logging.warning(
            "Źródło nie udostępnia zdjęć dla produktów %s. Wyeksportowano je z images=[]; "
            "wymagają rozstrzygnięcia przed importem sklepu.", ", ".join(without_images)
        )
    if not report["complete"]:
        details = " Produkty z błędem: " + ", ".join(failed) + "." if failed else ""
        raise RuntimeError(
            "Niepełne pobieranie." + details
            + " Sprawdź scrape-report.json; zachowano wcześniejsze dane."
        )
    return report


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--refresh", action="store_true", help="Pobierz ponownie również szczegóły zapisanych produktów.")
    parser.add_argument("--refresh-variants", action="store_true", help="Ponownie zweryfikuj ceny wszystkich zapisanych produktów z wariantami.")
    parser.add_argument("--headless", action="store_true", help="Uruchom bez widocznego okna przeglądarki.")
    parser.add_argument("--output-dir", type=Path, default=RESULTS_DIR)
    args = parser.parse_args()
    crawler = store_crawler(
        cookies=os.environ.get("SCRAPER_CF_CLEARANCE", ""),
        headless=args.headless,
    )
    try:
        scrape_catalog(crawler, args.output_dir, refresh=args.refresh, refresh_variants=args.refresh_variants)
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
