from __future__ import annotations

import json
import logging
import tempfile
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple, Union

logger = logging.getLogger("product_import")

_CURRENT_DIR = Path(__file__).resolve().parent
_PROJECT_ROOT = _CURRENT_DIR.parent.parent


def get_default_category_mapping_path(base_dir: Optional[Path] = None) -> Path:
    """Zwraca domyślną ścieżkę do pliku mapowania kategorii."""
    directory = base_dir or _PROJECT_ROOT / "scraper-results"
    # Pierwszeństwo ma plik dedykowany instancji, jeśli istnieje
    instance_path = directory / "category_mapping_localhost.json"
    if instance_path.is_file():
        return instance_path
    return directory / "category_mapping.json"


def get_default_product_mapping_path(base_dir: Optional[Path] = None) -> Path:
    """Zwraca domyślną ścieżkę do pliku mapowania produktów."""
    directory = base_dir or _PROJECT_ROOT / "scraper-results"
    return directory / "product_mapping.json"


def load_category_mapping(path: Optional[Union[str, Path]] = None) -> Dict[str, int]:
    """Wczytuje mapowanie kategorii ze wskazanego pliku lub domyślnej ścieżki."""
    p = Path(path) if path else get_default_category_mapping_path()
    if not p.is_file():
        raise FileNotFoundError(f"Plik mapowania kategorii nie istnieje: {p}")

    with p.open("r", encoding="utf-8") as f:
        data = json.load(f)

    result: Dict[str, int] = {}
    for k, v in data.items():
        if str(k).startswith("_"):
            continue
        try:
            result[str(k)] = int(v)
        except (ValueError, TypeError):
            pass

    logger.info("Wczytano %d wpisów z pliku mapowania kategorii: %s", len(result), p)
    return result


def resolve_product_categories(
    category_paths: List[List[str]],
    breadcrumbs: List[str],
    category_mapping: Dict[str, int],
) -> Tuple[int, List[int]]:
    """
    Rozwiązuje przypisania kategorii dla produktu:
    - Wyznacza główną kategorię (id_category_default) z breadcrumbs lub najgłębszej ścieżki.
    - Zbiera wszystkie unikalne identyfikatory kategorii (liście i przodków).
    
    Zwraca: (default_category_id, list_of_all_associated_category_ids).
    """
    if not category_paths:
        raise ValueError("Brak ścieżek kategorii dla produktu.")

    # 1. Wyznaczenie preferowanej ścieżki głównej z breadcrumbs
    preferred_path: Optional[List[str]] = None
    if len(breadcrumbs) >= 3:
        candidate = breadcrumbs[1:-1]
        if candidate in category_paths:
            preferred_path = candidate

    if not preferred_path:
        # Wybór najgłębszej ścieżki z deterministycznym rozstrzyganiem remisów
        sorted_paths = sorted(
            category_paths,
            key=lambda p: (-len(p), " > ".join(p)),
        )
        preferred_path = sorted_paths[0]

    # Rozwiązanie ID kategorii domyślnej
    default_id: Optional[int] = None
    default_keys = [
        " > ".join(preferred_path),
        " / ".join(preferred_path),
        preferred_path[-1],
    ]
    for k in default_keys:
        if k in category_mapping:
            default_id = category_mapping[k]
            break

    if default_id is None:
        raise ValueError(
            f"Nie udało się rozwiązać kategorii domyślnej dla ścieżki: {' > '.join(preferred_path)}"
        )

    # 2. Rozwiązanie wszystkich ścieżek i ich węzłów przodków
    associated_ids: set[int] = set()
    for path in category_paths:
        for length in range(1, len(path) + 1):
            sub_path = path[:length]
            keys_to_try = [
                " > ".join(sub_path),
                " / ".join(sub_path),
                sub_path[-1],
            ]
            cid = None
            for k in keys_to_try:
                if k in category_mapping:
                    cid = category_mapping[k]
                    break
            if cid is not None:
                associated_ids.add(cid)
            else:
                logger.debug("Brak mapowania dla węzła kategorii: %s", " > ".join(sub_path))

    associated_ids.add(default_id)
    sorted_ids = sorted(associated_ids)

    return default_id, sorted_ids


def save_product_mapping(
    mapping_path: Path | str,
    mapping: Dict[str, Any],
) -> None:
    """
    Zapisuje słownik mapowania produktów atomowo do pliku JSON.
    Ustawia uprawnienia 0664 (rw-rw-r--), aby plik był czytelny w Dockerze.
    """
    p = Path(mapping_path)
    p.parent.mkdir(parents=True, exist_ok=True)

    temp_path: Optional[Path] = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="w",
            encoding="utf-8",
            dir=p.parent,
            delete=False,
            suffix=".tmp",
        ) as temp_file:
            temp_path = Path(temp_file.name)
            json.dump(mapping, temp_file, ensure_ascii=False, indent=2)
            temp_file.write("\n")
        temp_path.chmod(0o664)
        temp_path.replace(p)
        temp_path = None
    finally:
        if temp_path is not None and temp_path.exists():
            temp_path.unlink(missing_ok=True)

    logger.info("Zapisano mapowanie produktów (%d wpisów) do: %s", len(mapping), p)


def load_product_mapping(mapping_path: Path | str) -> Dict[str, int]:
    """Wczytuje zapisane mapowanie produktów z pliku JSON."""
    p = Path(mapping_path)
    if not p.is_file():
        return {}

    with p.open("r", encoding="utf-8") as f:
        data = json.load(f)

    result: Dict[str, int] = {}
    for k, v in data.items():
        if str(k).startswith("_"):
            continue
        try:
            result[str(k)] = int(v)
        except (ValueError, TypeError):
            pass

    return result
