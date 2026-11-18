from __future__ import annotations

import logging
import sys

# Konfiguracja loggera
logger = logging.getLogger("category_import")
if not logger.handlers:
    _handler = logging.StreamHandler(sys.stdout)
    _formatter = logging.Formatter("[%(asctime)s] [%(levelname)s] %(message)s", datefmt="%H:%M:%S")
    _handler.setFormatter(_formatter)
    logger.addHandler(_handler)
    logger.setLevel(logging.INFO)

from .models import (
    CategoryItem,
    ImportStats,
    PRESTASHOP_HOME_CATEGORY_ID,
)
from .utils import (
    PL_CHAR_TRANSLATION,
    slugify,
    _extract_category_name,
)
from .validator import (
    validate_category_record,
    load_and_validate_categories,
)
from .mapping import (
    save_category_mapping,
    load_category_mapping,
    get_default_mapping_path,
    sanitize_instance_host,
    normalize_instance_url,
)
from .prestashop_sync import (
    fetch_existing_categories,
    build_prestashop_hierarchy_index,
    fetch_active_languages,
    build_category_payload,
    normalize_multilang_value,
)
from .orchestrator import (
    import_categories,
)
from .cli import (
    print_summary,
    main,
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
