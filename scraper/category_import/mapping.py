from __future__ import annotations

import json
import logging
from pathlib import Path
import tempfile
from typing import Dict, Optional

from .models import PRESTASHOP_HOME_CATEGORY_ID

logger = logging.getLogger("category_import")


def save_category_mapping(mapping_path: Path, mapping: Dict[str, int]) -> None:
    """
    Zapisuje słownik mapowania kategorii atomowo do pliku JSON.
    Ustawia uprawnienia 0664, aby plik był czytelny przez inne procesy (Docker, PHP).
    """
    mapping_path.parent.mkdir(parents=True, exist_ok=True)
    mapping["home"] = PRESTASHOP_HOME_CATEGORY_ID
    mapping["Strona główna"] = PRESTASHOP_HOME_CATEGORY_ID

    temp_path: Optional[Path] = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="w",
            encoding="utf-8",
            dir=mapping_path.parent,
            delete=False,
            suffix=".tmp",
        ) as temp_file:
            temp_path = Path(temp_file.name)
            json.dump(mapping, temp_file, ensure_ascii=False, indent=2)
            temp_file.write("\n")
        temp_path.chmod(0o664)
        temp_path.replace(mapping_path)
        temp_path = None
    finally:
        if temp_path is not None and temp_path.exists():
            temp_path.unlink(missing_ok=True)

    logger.info("Zapisano mapowanie %d wpisów do: %s", len(mapping), mapping_path)


def load_category_mapping(mapping_path: Path) -> Dict[str, int]:
    """
    Wczytuje zapisany słownik mapowania kategorii z pliku JSON.
    """
    if not mapping_path.is_file():
        raise FileNotFoundError(f"Plik mapowania kategorii nie istnieje: {mapping_path}")

    with mapping_path.open("r", encoding="utf-8") as f:
        data = json.load(f)

    if not isinstance(data, dict):
        raise ValueError(f"Niepoprawny format pliku mapowania: {mapping_path}")

    return {str(k): int(v) for k, v in data.items()}
