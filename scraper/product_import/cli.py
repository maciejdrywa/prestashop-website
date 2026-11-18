from __future__ import annotations

import argparse
import logging
from pathlib import Path
import sys

try:
    from prestashop_api import (
        PrestaShopClient,
        PrestaShopAuthError,
        PrestaShopConnectionError,
    )
except ImportError:
    from scraper.prestashop_api import (
        PrestaShopClient,
        PrestaShopAuthError,
        PrestaShopConnectionError,
    )

from .models import ProductImportStats
from .mapping import get_default_product_mapping_path
from .orchestrator import import_products

logger = logging.getLogger("product_import")

_CURRENT_DIR = Path(__file__).resolve().parent
_PROJECT_ROOT = _CURRENT_DIR.parent.parent


def print_summary(stats: ProductImportStats, mapping_path: Path) -> None:
    """Wyświetla czytelne podsumowanie procesu importu produktów w konsoli."""
    print("\n" + "=" * 65)
    print("           PODSUMOWANIE IMPORTU PRODUKTÓW PRESTASHOP")
    print("=" * 65)
    print(f"  Łącznie rekordów ze scrapowania:      {stats.total}")
    print(f"  Nowo utworzone produkty:             {stats.created}")
    print(f"  Zaktualizowane produkty (PUT):       {stats.updated}")
    print(f"  Pominięte produkty (dirty-check):     {stats.skipped}")
    print(f"  Błędy produktów:                     {stats.errors}")
    print("-" * 65)
    print(f"  Utworzone kombinacje/warianty:       {stats.combinations_created}")
    print(f"  Zaktualizowane kombinacje:           {stats.combinations_updated}")
    print(f"  Pominięte kombinacje:                {stats.combinations_skipped}")
    print("-" * 65)
    print(f"  Zaktualizowane stany magazynowe:     {stats.stocks_updated}")
    print(f"  Pominięte stany (dirty-check):       {stats.stocks_skipped}")
    print("-" * 65)
    print(f"  Plik mapowania zapisano w:           {mapping_path}")
    print("=" * 65)
    if stats.error_details:
        print("\nSzczegóły napotkanych problemów:")
        for err in stats.error_details[:10]:
            print(f"  [BŁĄD] {err}")
        if len(stats.error_details) > 10:
            print(f"  ... i jeszcze {len(stats.error_details) - 10} innych.")
    print()


def main() -> int:
    """Punkt wejścia CLI dla skryptu importu produktów."""
    parser = argparse.ArgumentParser(
        description="Automatyczny import i aktualizacja produktów, wariantów i stanów w PrestaShop Webservice API."
    )
    parser.add_argument(
        "--products-file",
        type=Path,
        default=None,
        help="Ścieżka do pliku products.json (domyślnie scraper-results/products.json)",
    )
    parser.add_argument(
        "--category-mapping-file",
        type=Path,
        default=None,
        help="Ścieżka do pliku category_mapping.json",
    )
    parser.add_argument(
        "--product-mapping-file",
        type=Path,
        default=None,
        help="Ścieżka wyjściowa pliku mapowania produktów (domyślnie scraper-results/product_mapping.json)",
    )
    parser.add_argument(
        "--skip-existing",
        action="store_true",
        help="Pomiń istniejące produkty zamiast wysyłać zapytania aktualizujące (PUT).",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=None,
        help="Ogranicz liczbę przetwarzanych produktów (np. do szybkiej weryfikacji).",
    )

    args = parser.parse_args()

    logging.basicConfig(
        level=logging.INFO,
        format="[%(asctime)s] [%(levelname)s] %(message)s",
        datefmt="%H:%M:%S",
    )

    try:
        client = PrestaShopClient()
        client.check_connection()
    except (PrestaShopAuthError, PrestaShopConnectionError, ValueError) as exc:
        logger.error("Błąd połączenia lub autoryzacji PrestaShop: %s", exc)
        return 1

    mapping_path = (
        args.product_mapping_file
        if args.product_mapping_file
        else get_default_product_mapping_path(_PROJECT_ROOT / "scraper-results")
    )

    try:
        stats, _ = import_products(
            client=client,
            products_file=args.products_file,
            category_mapping_file=args.category_mapping_file,
            product_mapping_file=args.product_mapping_file,
            update_existing=not args.skip_existing,
            limit=args.limit,
        )
        print_summary(stats, mapping_path)
        return 0 if stats.errors == 0 else 1
    except Exception as exc:
        logger.exception("Krytyczny błąd podczas importu produktów: %s", exc)
        return 1
