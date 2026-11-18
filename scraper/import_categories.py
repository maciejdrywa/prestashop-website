"""
Fasada i punkt wejścia CLI dla modułu automatycznego tworzenia i aktualizowania
hierarchicznej struktury kategorii w PrestaShop Webservice API.

Wszystkie komponenty zostały zrefaktoryzowane do wyspecjalizowanych modułów w pakiecie
scraper.category_import:
- models.py: struktury danych CategoryItem, ImportStats
- utils.py: slugify, transliteracja, _extract_category_name
- validator.py: walidacja rekordów i wczytywanie JSON
- mapping.py: zapis i odczyt pliku mapowania
- prestashop_sync.py: komunikacja z PrestaShop Webservice API, budowa hierarchii
- orchestrator.py: pętla importu, dirty-checking, obsługa konfliktów i błędów rodzica
- cli.py: obsługa argumentów wiersza poleceń i podsumowanie
"""

from __future__ import annotations

from pathlib import Path
import sys

# Zapewnienie poprawnego importu niezależnie od katalogu wywołania
_CURRENT_DIR = Path(__file__).resolve().parent
_PROJECT_ROOT = _CURRENT_DIR.parent
if str(_CURRENT_DIR) not in sys.path:
    sys.path.insert(0, str(_CURRENT_DIR))
if str(_PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(_PROJECT_ROOT))

from category_import import (
    PRESTASHOP_HOME_CATEGORY_ID,
    CategoryItem,
    ImportStats,
    PL_CHAR_TRANSLATION,
    slugify,
    _extract_category_name,
    validate_category_record,
    load_and_validate_categories,
    save_category_mapping,
    load_category_mapping,
    fetch_existing_categories,
    build_prestashop_hierarchy_index,
    fetch_active_languages,
    build_category_payload,
    import_categories,
    print_summary,
    main,
    logger,
)

__all__ = [
    "PRESTASHOP_HOME_CATEGORY_ID",
    "CategoryItem",
    "ImportStats",
    "PL_CHAR_TRANSLATION",
    "slugify",
    "_extract_category_name",
    "validate_category_record",
    "load_and_validate_categories",
    "save_category_mapping",
    "load_category_mapping",
    "fetch_existing_categories",
    "build_prestashop_hierarchy_index",
    "fetch_active_languages",
    "build_category_payload",
    "import_categories",
    "print_summary",
    "main",
    "logger",
]

if __name__ == "__main__":
    sys.exit(main())
