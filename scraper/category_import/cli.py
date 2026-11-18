from __future__ import annotations

import argparse
import logging
from pathlib import Path

from prestashop_api import (
    PrestaShopAuthError,
    PrestaShopClient,
    PrestaShopConnectionError,
)

from .mapping import get_default_mapping_path
from .models import ImportStats
from .orchestrator import import_categories

logger = logging.getLogger("category_import")

_CURRENT_DIR = Path(__file__).resolve().parent
_PROJECT_ROOT = _CURRENT_DIR.parent.parent


def print_summary(stats: ImportStats, mapping_path: Path) -> None:
    """Wyświetla estetyczne podsumowanie procesu importu w konsoli."""
    print("\n" + "=" * 60)
    print("           PODSUMOWANIE IMPORTU KATEGORII PRESTASHOP")
    print("=" * 60)
    print(f"  Łącznie rekordów ze scrapowania: {stats.total}")
    print(f"  Nowo utworzone kategorie:       {stats.created}")
    print(f"  Zaktualizowane kategorie:       {stats.updated}")
    print(f"  Pominięte kategorie:            {stats.skipped}")
    print(f"  Błędy:                          {stats.errors}")
    print(f"  Plik mapowania zapisano w:      {mapping_path}")
    print("=" * 60)
    if stats.error_details:
        print("\nSzczegóły napotkanych błędów:")
        for err in stats.error_details[:10]:
            print(f"  [BŁĄD] {err}")
        if len(stats.error_details) > 10:
            print(f"  ... i jeszcze {len(stats.error_details) - 10} innych.")
    print()


def main() -> int:
    """Punkt wejścia CLI dla skryptu importu kategorii."""
    parser = argparse.ArgumentParser(
        description="Automatyczny import i aktualizacja kategorii w PrestaShop Webservice API."
    )
    parser.add_argument(
        "--categories-file",
        type=Path,
        default=None,
        help="Ścieżka do pliku categories.json (domyślnie scraper-results/categories.json)",
    )
    parser.add_argument(
        "--mapping-file",
        type=Path,
        default=None,
        help="Ścieżka wyjściowa pliku mapowania (domyślnie scraper-results/category_mapping.json)",
    )
    parser.add_argument(
        "--skip-existing",
        action="store_true",
        help="Pomiń istniejące kategorie zamiast wysyłać zapytania aktualizujące (PUT).",
    )

    args = parser.parse_args()

    try:
        client = PrestaShopClient()
        client.check_connection()
    except (PrestaShopAuthError, PrestaShopConnectionError, ValueError) as exc:
        logger.error("Błąd połączenia lub autoryzacji PrestaShop: %s", exc)
        return 1

    mapping_path = (
        args.mapping_file
        if args.mapping_file
        else get_default_mapping_path(_PROJECT_ROOT / "scraper-results", getattr(client, "api_url", None))
    )

    try:
        stats, _ = import_categories(
            client=client,
            categories_file=args.categories_file,
            mapping_file=args.mapping_file,
            update_existing=not args.skip_existing,
        )
        print_summary(stats, mapping_path)
        return 0 if stats.errors == 0 else 1
    except Exception as exc:
        logger.exception("Krytyczny błąd podczas importu kategorii: %s", exc)
        return 1
