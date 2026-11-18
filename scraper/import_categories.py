"""
Fasada i punkt wejścia CLI dla modułu automatycznego tworzenia i aktualizowania
hierarchicznej struktury kategorii w PrestaShop Webservice API.

Wszystkie komponenty zostały zrefaktoryzowane do wyspecjalizowanych modułów w pakiecie
scraper.category_import:
- models.py: struktury danych CategoryItem, ImportStats
- utils.py: slugify, transliteracja, _extract_category_name
- validator.py: walidacja rekordów i wczytywanie JSON
- mapping.py: zapis i odczyt pliku mapowania, dobór pliku per instancja
- prestashop_sync.py: komunikacja z PrestaShop Webservice API, budowa hierarchii, formatowanie wielojęzyczne
- orchestrator.py: pętla importu, dirty-checking, obsługa konfliktów i błędów rodzica
- cli.py: obsługa argumentów wiersza poleceń i podsumowanie
"""

from __future__ import annotations

import sys
from pathlib import Path

# Zapewnienie poprawnego importu niezależnie od katalogu wywołania
_CURRENT_DIR = Path(__file__).resolve().parent
_PROJECT_ROOT = _CURRENT_DIR.parent
if str(_CURRENT_DIR) not in sys.path:
    sys.path.insert(0, str(_CURRENT_DIR))
if str(_PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(_PROJECT_ROOT))

# Import korzysta ze ścieżek ustawionych powyżej przy uruchamianiu przez python -m.
from category_import import (  # noqa: E402
    PL_CHAR_TRANSLATION,
    PRESTASHOP_HOME_CATEGORY_ID,
    CategoryItem,
    ImportStats,
    _extract_category_name,
    build_category_payload,
    build_prestashop_hierarchy_index,
    fetch_active_languages,
    fetch_existing_categories,
    get_default_mapping_path,
    import_categories,
    load_and_validate_categories,
    load_category_mapping,
    logger,
    main,
    normalize_instance_url,
    normalize_multilang_value,
    print_summary,
    sanitize_instance_host,
    save_category_mapping,
    slugify,
    validate_category_record,
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
    "get_default_mapping_path",
    "sanitize_instance_host",
    "normalize_instance_url",
    "fetch_existing_categories",
    "build_prestashop_hierarchy_index",
    "fetch_active_languages",
    "build_category_payload",
    "normalize_multilang_value",
    "import_categories",
    "print_summary",
    "main",
    "logger",
]

if __name__ == "__main__":
    sys.exit(main())
