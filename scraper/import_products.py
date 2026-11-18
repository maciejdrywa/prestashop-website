"""
Fasada i punkt wejścia CLI dla modułu automatycznego tworzenia i aktualizowania
produktów, kombinacji, cen, podatków oraz stanów magazynowych w PrestaShop Webservice API.

Wszystkie komponenty zostały zaimplementowane w dedykowanym pakiecie scraper.product_import:
- models.py: struktury danych ProductItem, VariantItem, ProductImportStats
- pricing.py: precyzja cen Decimal(6), mapowanie VAT do id_tax_rules_group, modyfikatory wariantów
- stock.py: weryfikacja i podział stanów 0–10 sztuk, twardy warunek 5–20% zer, out_of_stock=0
- utils.py: slugify, normalizacja link_rewrite
- validator.py: walidacja rekordów products.json, deterministyczne reguły wagi demonstracyjnej
- mapping.py: rozwiązywanie kategorii nadrzędnych i domyślnych, atomowy zapis product_mapping.json (0664)
- prestashop_sync.py: komunikacja z PrestaShop Webservice API, obsługa wariantów i stanów magazynowych
- orchestrator.py: główna pętla importu, dirty-checking, odporność na błędy per rekord
- cli.py: obsługa argumentów wiersza poleceń i estetyczne podsumowanie konsolowe
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

from product_import import (
    ProductItem,
    VariantItem,
    ProductImportStats,
    VAT_RATE_TO_TAX_RULES_GROUP,
    NETTO_PRECISION,
    GROSS_PRECISION,
    to_decimal,
    round_netto,
    round_gross,
    calculate_gross,
    calculate_netto,
    get_tax_rules_group_id,
    calculate_price_modifier,
    format_decimal,
    INITIAL_STOCK_MIN,
    INITIAL_STOCK_MAX,
    INITIAL_UNAVAILABLE_MIN_RATIO,
    INITIAL_UNAVAILABLE_MAX_RATIO,
    DEFAULT_OUT_OF_STOCK,
    validate_stock_quantity,
    verify_stock_ratio,
    generate_deterministic_stock_plan,
    distribute_stock_to_variants,
    PL_CHAR_TRANSLATION,
    slugify,
    CATEGORY_WEIGHT_RULES,
    DEFAULT_DEMO_WEIGHT,
    resolve_product_weight,
    validate_variant_record,
    validate_product_record,
    load_and_validate_products,
    get_default_category_mapping_path,
    get_default_product_mapping_path,
    load_category_mapping,
    resolve_product_categories,
    save_product_mapping,
    load_product_mapping,
    fetch_active_languages,
    fetch_existing_products_index,
    fetch_existing_combinations_index,
    fetch_product_options_cache,
    fetch_option_values_cache,
    ensure_product_option_group,
    ensure_product_option_value,
    fetch_product_features_cache,
    fetch_feature_values_cache,
    ensure_product_feature,
    ensure_product_feature_value,
    build_product_payload,
    is_product_dirty,
    build_combination_payload,
    is_combination_dirty,
    fetch_stock_availables_for_product,
    update_stock_available,
    import_products,
    print_summary,
    main,
    logger,
)

__all__ = [
    "ProductItem",
    "VariantItem",
    "ProductImportStats",
    "VAT_RATE_TO_TAX_RULES_GROUP",
    "NETTO_PRECISION",
    "GROSS_PRECISION",
    "to_decimal",
    "round_netto",
    "round_gross",
    "calculate_gross",
    "calculate_netto",
    "get_tax_rules_group_id",
    "calculate_price_modifier",
    "format_decimal",
    "INITIAL_STOCK_MIN",
    "INITIAL_STOCK_MAX",
    "INITIAL_UNAVAILABLE_MIN_RATIO",
    "INITIAL_UNAVAILABLE_MAX_RATIO",
    "DEFAULT_OUT_OF_STOCK",
    "validate_stock_quantity",
    "verify_stock_ratio",
    "generate_deterministic_stock_plan",
    "distribute_stock_to_variants",
    "PL_CHAR_TRANSLATION",
    "slugify",
    "CATEGORY_WEIGHT_RULES",
    "DEFAULT_DEMO_WEIGHT",
    "resolve_product_weight",
    "validate_variant_record",
    "validate_product_record",
    "load_and_validate_products",
    "get_default_category_mapping_path",
    "get_default_product_mapping_path",
    "load_category_mapping",
    "resolve_product_categories",
    "save_product_mapping",
    "load_product_mapping",
    "fetch_active_languages",
    "fetch_existing_products_index",
    "fetch_existing_combinations_index",
    "fetch_product_options_cache",
    "fetch_option_values_cache",
    "ensure_product_option_group",
    "ensure_product_option_value",
    "fetch_product_features_cache",
    "fetch_feature_values_cache",
    "ensure_product_feature",
    "ensure_product_feature_value",
    "build_product_payload",
    "is_product_dirty",
    "build_combination_payload",
    "is_combination_dirty",
    "fetch_stock_availables_for_product",
    "update_stock_available",
    "import_products",
    "print_summary",
    "main",
    "logger",
]

if __name__ == "__main__":
    sys.exit(main())
