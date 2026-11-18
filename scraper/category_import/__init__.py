from __future__ import annotations

import logging
import sys

from .cli import (
    main,
    print_summary,
)
from .mapping import (
    get_default_mapping_path,
    load_category_mapping,
    normalize_instance_url,
    sanitize_instance_host,
    save_category_mapping,
)
from .models import (
    PRESTASHOP_HOME_CATEGORY_ID,
    CategoryItem,
    ImportStats,
)
from .orchestrator import (
    import_categories,
)
from .prestashop_sync import (
    build_category_payload,
    build_prestashop_hierarchy_index,
    fetch_active_languages,
    fetch_existing_categories,
    normalize_multilang_value,
)
from .utils import (
    PL_CHAR_TRANSLATION,
    _extract_category_name,
    slugify,
)
from .validator import (
    load_and_validate_categories,
    validate_category_record,
)

# Konfiguracja loggera
logger = logging.getLogger("category_import")
if not logger.handlers:
    _handler = logging.StreamHandler(sys.stdout)
    _formatter = logging.Formatter("[%(asctime)s] [%(levelname)s] %(message)s", datefmt="%H:%M:%S")
    _handler.setFormatter(_formatter)
    logger.addHandler(_handler)
    logger.setLevel(logging.INFO)

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
