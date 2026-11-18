from __future__ import annotations

import json
import logging
import re
import tempfile
import urllib.parse
from pathlib import Path
from typing import Any, Dict, Optional

from .models import PRESTASHOP_HOME_CATEGORY_ID

logger = logging.getLogger("category_import")


def normalize_instance_url(url: str) -> str:
    """Normalizuje URL instancji PrestaShop do celów porównawczych."""
    parsed = urllib.parse.urlparse(url.strip())
    netloc = parsed.netloc.lower()
    path = parsed.path.rstrip("/")
    return f"{parsed.scheme.lower()}://{netloc}{path}"


def sanitize_instance_host(api_url: str) -> str:
    """Tworzy bezpieczny identyfikator hosta/portu instancji na potrzeby nazwy pliku mapowania."""
    parsed = urllib.parse.urlparse(api_url)
    host = parsed.netloc or parsed.path.split("/")[0] or "default"
    path_suffix = parsed.path.strip("/").replace("/", "_")
    combined = f"{host}_{path_suffix}" if path_suffix and path_suffix != "api" else host
    safe = re.sub(r"[^a-zA-Z0-9_\-\.]", "_", combined).strip("_")
    return safe or "default"


def get_default_mapping_path(base_dir: Path, api_url: Optional[str] = None) -> Path:
    """
    Zwraca ścieżkę do pliku mapowania dedykowaną dla danej instancji sklepu.
    Jeśli api_url jest podany, tworzy plik np. category_mapping_{host}.json.
    """
    if not api_url:
        return base_dir / "category_mapping.json"
    host_slug = sanitize_instance_host(api_url)
    return base_dir / f"category_mapping_{host_slug}.json"


def save_category_mapping(
    mapping_path: Path,
    mapping: Dict[str, Any],
    instance_url: Optional[str] = None,
) -> None:
    """
    Zapisuje słownik mapowania kategorii atomowo do pliku JSON.
    Ustawia uprawnienia 0664, aby plik był czytelny przez inne procesy (Docker, PHP).
    Opcjonalnie zapisuje metadane instancji (_instance_url).
    """
    mapping_path.parent.mkdir(parents=True, exist_ok=True)
    mapping["home"] = PRESTASHOP_HOME_CATEGORY_ID
    mapping["Strona główna"] = PRESTASHOP_HOME_CATEGORY_ID

    if instance_url:
        mapping["_instance_url"] = instance_url

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

    data_count = len([k for k in mapping if not str(k).startswith("_")])
    logger.info("Zapisano mapowanie %d wpisów do: %s", data_count, mapping_path)


def load_category_mapping(
    mapping_path: Path,
    expected_instance_url: Optional[str] = None,
) -> Dict[str, int]:
    """
    Wczytuje zapisany słownik mapowania kategorii z pliku JSON.
    Weryfikuje zgodność instancji sklepu, jeśli podano expected_instance_url.
    W przypadku niezgodności instancji ignoruje niepewne ID i zwraca pusty słownik.
    Filtruje metadane (klucze rozpoczynające się od '_').
    """
    if not mapping_path.is_file():
        raise FileNotFoundError(f"Plik mapowania kategorii nie istnieje: {mapping_path}")

    with mapping_path.open("r", encoding="utf-8") as f:
        data = json.load(f)

    if not isinstance(data, dict):
        raise ValueError(f"Niepoprawny format pliku mapowania: {mapping_path}")

    # Weryfikacja instancji sklepu
    if expected_instance_url:
        if "_instance_url" not in data:
            logger.warning(
                "Plik mapowania %s nie zawiera informacji o instancji pochodzenia (_instance_url). "
                "Zignorowano niepewne ID z pliku.",
                mapping_path,
            )
            return {}

        saved_instance = str(data["_instance_url"]).rstrip("/")
        expected_instance = str(expected_instance_url).rstrip("/")
        if normalize_instance_url(saved_instance) != normalize_instance_url(expected_instance):
            logger.warning(
                "Wykryto niezgodność instancji sklepu w pliku mapowania %s: "
                "plik powstał dla '%s', a bieżąca instancja to '%s'. "
                "Zignorowano niepewne ID z pliku.",
                mapping_path,
                saved_instance,
                expected_instance,
            )
            return {}

    # Zwracanie wyłącznie poprawnych wpisów mapowania ID (odrzucenie metadanych)
    clean_map: Dict[str, int] = {}
    for k, v in data.items():
        if str(k).startswith("_"):
            continue
        try:
            clean_map[str(k)] = int(v)
        except (ValueError, TypeError):
            continue

    return clean_map
