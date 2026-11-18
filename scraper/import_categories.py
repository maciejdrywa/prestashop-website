"""
Moduł odpowiedzialny za automatyczne tworzenie i aktualizowanie hierarchicznej struktury
kategorii w PrestaShop Webservice API na podstawie danych ze scrapowania.

Funkcjonalności:
- Wczytywanie i walidacja danych z scraper-results/categories.json.
- Budowa kolejki tworzenia z zachowaniem hierarchii (sortowanie wg długości category_path).
- Pobieranie istniejących kategorii ze sklepu (display=[id,id_parent,name]) w celu idempotencji.
- Tworzenie brakujących kategorii (POST) i aktualizacja istniejących (PUT).
- Generowanie poprawnych wielojęzycznych pól i slugów (link_rewrite) z obsługą polskich znaków.
- Zapis słownika mapowania do scraper-results/category_mapping.json.
- Odporność na błędy API i szczegółowe podsumowanie wykonania.
"""

from __future__ import annotations

import argparse
from dataclasses import dataclass, field
import json
import logging
from pathlib import Path
import re
import sys
import tempfile
from typing import Any, Dict, List, Optional, Set, Tuple, Union
import unicodedata

# Zapewnienie poprawnego importu niezależnie od katalogu wywołania
_CURRENT_DIR = Path(__file__).resolve().parent
_PROJECT_ROOT = _CURRENT_DIR.parent
if str(_CURRENT_DIR) not in sys.path:
    sys.path.insert(0, str(_CURRENT_DIR))
if str(_PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(_PROJECT_ROOT))

from prestashop_api import (
    PrestaShopClient,
    PrestaShopAPIError,
    PrestaShopAuthError,
    PrestaShopConnectionError,
    PrestaShopNotFoundError,
    PrestaShopValidationError,
)

# Konfiguracja loggera
logger = logging.getLogger("import_categories")
if not logger.handlers:
    _handler = logging.StreamHandler(sys.stdout)
    _formatter = logging.Formatter("[%(asctime)s] [%(levelname)s] %(message)s", datefmt="%H:%M:%S")
    _handler.setFormatter(_formatter)
    logger.addHandler(_handler)
    logger.setLevel(logging.INFO)

# ID głównej kategorii bazowej "Strona główna" w PrestaShop
PRESTASHOP_HOME_CATEGORY_ID = 2

# Tablica transliteracji polskich znaków dla link_rewrite (slug)
PL_CHAR_TRANSLATION = str.maketrans({
    "ą": "a", "ć": "c", "ę": "e", "ł": "l", "ń": "n", "ó": "o", "ś": "s", "ź": "z", "ż": "z",
    "Ą": "a", "Ć": "c", "Ę": "e", "Ł": "l", "Ń": "n", "Ó": "o", "Ś": "s", "Ź": "z", "Ż": "z",
})


def slugify(text: str) -> str:
    """
    Tworzy bezpieczny, czytelny slug dla pola link_rewrite w PrestaShop.
    Transliteruje polskie znaki, usuwa znaki niedozwolone, zamienia spacje na myślniki
    oraz obcina wynik do 128 znaków (limit PrestaShop).
    """
    if not text:
        return "kategoria"
    text = text.translate(PL_CHAR_TRANSLATION)
    text = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode("ascii")
    text = text.lower()
    text = re.sub(r"[^a-z0-9]+", "-", text)
    slug = text.strip("-")
    return slug[:128] if slug else "kategoria"


@dataclass
class CategoryItem:
    """Reprezentacja pojedynczej kategorii z pliku źródłowego categories.json."""
    name: str
    category_path: List[str]
    source_url: str
    parent_source_url: Optional[str] = None
    product_ids: List[str] = field(default_factory=list)
    raw_data: Dict[str, Any] = field(default_factory=dict)

    @property
    def depth(self) -> int:
        """Głębokość zagnieżdżenia kategorii w drzewie (1 = kategoria główna)."""
        return len(self.category_path)

    @property
    def path_key(self) -> Tuple[str, ...]:
        """Krotka nazw ścieżki kategorii."""
        return tuple(self.category_path)

    @property
    def formatted_path(self) -> str:
        """Czytelna tekstowa reprezentacja ścieżki kategorii (separator ' > ')."""
        return " > ".join(self.category_path)


@dataclass
class ImportStats:
    """Statystyki procesu importu kategorii."""
    total: int = 0
    created: int = 0
    updated: int = 0
    skipped: int = 0
    errors: int = 0
    error_details: List[str] = field(default_factory=list)


def validate_category_record(raw: Dict[str, Any], index: int) -> CategoryItem:
    """
    Waliduje pojedynczy rekord z pliku categories.json i zwraca obiekt CategoryItem.
    Rzuca ValueError w przypadku brakujących lub nieprawidłowych danych.
    """
    if not isinstance(raw, dict):
        raise ValueError(f"Rekord #{index} nie jest słownikiem: {raw}")

    name = str(raw.get("name", "")).strip()
    if not name:
        raise ValueError(f"Rekord #{index} posiada puste pole 'name': {raw}")

    category_path = raw.get("category_path")
    if not isinstance(category_path, list) or not category_path:
        raise ValueError(f"Rekord #{index} ('{name}') posiada nieprawidłowe pole 'category_path': {category_path}")

    cleaned_path = [str(p).strip() for p in category_path if str(p).strip()]
    if not cleaned_path:
        raise ValueError(f"Rekord #{index} ('{name}') posiada pustą listę 'category_path'.")

    # Weryfikacja spójności: ostatni element ścieżki powinien odpowiadać nazwie kategorii
    if cleaned_path[-1] != name:
        logger.debug(
            "Rekord #%d: nazwa '%s' różni się od ostatniego segmentu ścieżki '%s'. Ujednolicono.",
            index,
            name,
            cleaned_path[-1],
        )
        cleaned_path[-1] = name

    source_url = str(raw.get("source_url", "")).strip()
    if not source_url:
        raise ValueError(f"Rekord #{index} ('{name}') nie posiada wymaganego 'source_url'.")

    parent_source_url_raw = raw.get("parent_source_url")
    parent_source_url = str(parent_source_url_raw).strip() if parent_source_url_raw else None

    # Kategorie pierwszego poziomu nie powinny mieć parent_source_url
    if len(cleaned_path) == 1 and parent_source_url is not None:
        logger.debug("Kategoria korzenia '%s' miała przypisany parent_source_url: %s", name, parent_source_url)

    # Podkategorie powinny wskazywać na rodzica
    if len(cleaned_path) > 1 and parent_source_url is None:
        logger.warning("Podkategoria '%s' (głębokość %d) nie ma podanego parent_source_url.", name, len(cleaned_path))

    product_ids_raw = raw.get("product_ids", [])
    product_ids = [str(pid) for pid in product_ids_raw] if isinstance(product_ids_raw, list) else []

    return CategoryItem(
        name=name,
        category_path=cleaned_path,
        source_url=source_url,
        parent_source_url=parent_source_url,
        product_ids=product_ids,
        raw_data=raw,
    )


def load_and_validate_categories(categories_path: Path) -> List[CategoryItem]:
    """
    Wczytuje i waliduje listę kategorii z pliku JSON.
    Sortuje kategorie rosnąco według długości category_path (gwarancja przetwarzania rodziców przed dziećmi).
    """
    if not categories_path.is_file():
        raise FileNotFoundError(f"Plik kategorii nie istnieje: {categories_path}")

    with categories_path.open("r", encoding="utf-8") as f:
        try:
            data = json.load(f)
        except json.JSONDecodeError as exc:
            raise ValueError(f"Błąd składni JSON w pliku {categories_path}: {exc}") from exc

    if not isinstance(data, list):
        raise ValueError(f"Oczekiwano listy w pliku {categories_path}, otrzymano: {type(data).__name__}")

    items: List[CategoryItem] = []
    for idx, raw_record in enumerate(data):
        item = validate_category_record(raw_record, idx)
        items.append(item)

    # Sortowanie hierarchiczne: najpierw poziom 1 (długość 1), potem 2, potem 3...
    items.sort(key=lambda c: (len(c.category_path), c.name))
    logger.info("Wczytano i zwalidowano %d kategorii z pliku: %s", len(items), categories_path)
    return items


def _extract_category_name(name_val: Any) -> str:
    """Pomocnicze wyciąganie nazwy lub wartości tekstowej pola z odpowiedzi PrestaShop (zarówno string jak i wielojęzyczne)."""
    if isinstance(name_val, str):
        return name_val.strip()
    if isinstance(name_val, list):
        for item in name_val:
            if isinstance(item, dict):
                if "value" in item and item["value"]:
                    return str(item["value"]).strip()
                if "#text" in item and item["#text"]:
                    return str(item["#text"]).strip()
    if isinstance(name_val, dict):
        if "value" in name_val and name_val["value"]:
            return str(name_val["value"]).strip()
        if "#text" in name_val and name_val["#text"]:
            return str(name_val["#text"]).strip()
        for v in name_val.values():
            if v:
                return str(v).strip()
    return ""


def fetch_existing_categories(client: PrestaShopClient) -> List[Dict[str, Any]]:
    """
    Pobiera listę istniejących kategorii ze sklepu PrestaShop Webservice API
    z polami id, id_parent, name, link_rewrite oraz active.
    """
    logger.info("Pobieranie istniejących kategorii z PrestaShop API (display=[id,id_parent,name,link_rewrite,active])...")
    res = client.get("categories", params={"display": "[id,id_parent,name,link_rewrite,active]"})

    if isinstance(res, dict):
        raw = res.get("categories", [])
    elif isinstance(res, list):
        raw = res
    else:
        raw = []

    if isinstance(raw, dict):
        if "category" in raw:
            cat_list = raw["category"]
            categories = cat_list if isinstance(cat_list, list) else [cat_list]
        else:
            categories = [raw]
    elif isinstance(raw, list):
        categories = raw
    else:
        categories = []

    cleaned: List[Dict[str, Any]] = []
    for cat in categories:
        if not isinstance(cat, dict) or "id" not in cat:
            continue
        try:
            cid = int(cat["id"])
            pid = int(cat.get("id_parent", 0))
            name = _extract_category_name(cat.get("name", ""))
            link_rewrite = _extract_category_name(cat.get("link_rewrite", ""))
            try:
                active = int(cat.get("active", 1))
            except (ValueError, TypeError):
                active = 1
            cleaned.append({
                "id": cid,
                "id_parent": pid,
                "name": name,
                "link_rewrite": link_rewrite,
                "active": active,
            })
        except (ValueError, TypeError):
            continue

    logger.info("Znaleziono %d istniejących kategorii w PrestaShop.", len(cleaned))
    return cleaned


def build_prestashop_hierarchy_index(
    existing_categories: List[Dict[str, Any]],
) -> Tuple[Dict[Tuple[str, ...], int], Dict[Tuple[int, str], int], Dict[int, Dict[str, Any]]]:
    """
    Buduje indeksy wyszukiwania istniejących kategorii:
    1. path_index: mapowanie pełnej ścieżki od korzenia (id=2) na ID kategorii.
    2. parent_child_index: mapowanie (id_parent, name_lowercase) na ID kategorii.
    3. existing_by_id: słownik istniejących kategorii indeksowany po ID kategorii.
    """
    existing_by_id: Dict[int, Dict[str, Any]] = {c["id"]: c for c in existing_categories}
    path_index: Dict[Tuple[str, ...], int] = {}
    parent_child_index: Dict[Tuple[int, str], int] = {}

    for c in existing_categories:
        cid = c["id"]
        pid = c["id_parent"]
        name = c["name"]
        parent_child_index[(pid, name.lower())] = cid
        parent_child_index[(pid, name)] = cid

        if cid in (1, PRESTASHOP_HOME_CATEGORY_ID):
            continue

        # Odtwarzanie pełnej ścieżki od korzenia PRESTASHOP_HOME_CATEGORY_ID
        path_segments: List[str] = []
        curr: Optional[Dict[str, Any]] = c
        is_under_home = False
        visited_ids: Set[int] = set()

        while curr and curr["id"] not in (0, 1):
            if curr["id"] in visited_ids:
                logger.warning(
                    "Wykryto pętlę cykliczną w hierarchii kategorii PrestaShop dla ID %d (ścieżka: %s). Przerwano.",
                    curr["id"],
                    path_segments,
                )
                break
            visited_ids.add(curr["id"])
            path_segments.append(curr["name"])
            if curr["id_parent"] == PRESTASHOP_HOME_CATEGORY_ID:
                is_under_home = True
                break
            curr = existing_by_id.get(curr["id_parent"])

        if is_under_home and path_segments:
            full_path = tuple(reversed(path_segments))
            path_index[full_path] = cid
            path_index[tuple(p.lower() for p in full_path)] = cid

    return path_index, parent_child_index, existing_by_id


def fetch_active_languages(client: PrestaShopClient) -> List[str]:
    """
    Pobiera identyfikatory wszystkich aktywnych języków w sklepie PrestaShop.
    W przypadku braku uprawnień (Least Privilege) lub błędu, zwraca domyślnie ["1"].
    """
    try:
        res = client.get("languages", params={"display": "[id]", "filter[active]": "1"})
        if isinstance(res, dict):
            raw = res.get("languages", [])
        elif isinstance(res, list):
            raw = res
        else:
            raw = []

        if isinstance(raw, dict):
            if "language" in raw:
                lang_list = raw["language"]
                langs = lang_list if isinstance(lang_list, list) else [lang_list]
            else:
                langs = [raw]
        elif isinstance(raw, list):
            langs = raw
        else:
            langs = []

        lang_ids = [str(l["id"]) for l in langs if isinstance(l, dict) and "id" in l]
        return lang_ids if lang_ids else ["1"]
    except PrestaShopAuthError:
        logger.debug("Brak uprawnień Webservice do zasobu 'languages' (Least Privilege). Użyto domyślnego ID języka '1'.")
        return ["1"]
    except Exception as exc:
        logger.warning("Nie udało się pobrać listy aktywnych języków (%s), użyto ID domyślnego '1'.", exc)
        return ["1"]


def build_category_payload(
    item: CategoryItem,
    parent_id: int,
    category_id: Optional[int] = None,
    active_languages: Optional[List[str]] = None,
) -> Dict[str, Any]:
    """
    Tworzy słownik danych dla PrestaShop Webservice API z obsługą wielojęzyczności
    dla wszystkich aktywnych języków oraz sluga w polu link_rewrite.
    """
    langs = active_languages or ["1"]
    cat_slug = slugify(item.name)
    payload: Dict[str, Any] = {
        "name": {lid: item.name for lid in langs},
        "link_rewrite": {lid: cat_slug for lid in langs},
        "id_parent": parent_id,
        "active": 1,
    }
    if category_id is not None:
        payload["id"] = category_id
    return payload


def save_category_mapping(mapping_path: Path, mapping: Dict[str, int]) -> None:
    """
    Zapisuje słownik mapowania kategorii atomowo do pliku JSON.
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


def import_categories(
    client: Optional[PrestaShopClient] = None,
    categories_file: Optional[Union[str, Path]] = None,
    mapping_file: Optional[Union[str, Path]] = None,
    update_existing: bool = True,
) -> Tuple[ImportStats, Dict[str, int]]:
    """
    Główna funkcja importu i aktualizacji kategorii w PrestaShop.

    Parametry:
    - client: instancja PrestaShopClient (jeśli None, tworzona automatycznie ze zmiennych .env)
    - categories_file: ścieżka do categories.json (domyślnie scraper-results/categories.json)
    - mapping_file: ścieżka do category_mapping.json (domyślnie scraper-results/category_mapping.json)
    - update_existing: czy aktualizować istniejące kategorie (PUT), czy je tylko pominąć

    Zwraca:
    - (ImportStats, category_mapping_dict)
    """
    categories_path = (
        Path(categories_file) if categories_file else _PROJECT_ROOT / "scraper-results" / "categories.json"
    )
    mapping_path = (
        Path(mapping_file) if mapping_file else _PROJECT_ROOT / "scraper-results" / "category_mapping.json"
    )

    if client is None:
        client = PrestaShopClient()

    # 1. Wczytanie i walidacja danych ze scrapowania
    items = load_and_validate_categories(categories_path)

    # 2. Pobranie aktywnych języków oraz istniejących kategorii w celu zachowania pełnej idempotencji
    active_languages = fetch_active_languages(client)
    logger.info("Aktywne języki PrestaShop: %s", ", ".join(active_languages))

    existing_categories = fetch_existing_categories(client)
    path_index, parent_child_index, existing_by_id = build_prestashop_hierarchy_index(existing_categories)

    stats = ImportStats(total=len(items))
    category_mapping: Dict[str, int] = {}
    if mapping_path.is_file():
        try:
            category_mapping = load_category_mapping(mapping_path)
        except Exception as exc:
            logger.debug("Nie udało się załadować istniejącego pliku mapowania (%s): %s", mapping_path, exc)

    # 3. Przetwarzanie kategorii z zachowaniem kolejności rodzic -> dzieci
    for item in items:
        # Określenie ID rodzica w PrestaShop
        if item.depth == 1:
            target_parent_id = PRESTASHOP_HOME_CATEGORY_ID
        else:
            parent_path = tuple(item.category_path[:-1])
            lower_parent_path = tuple(p.lower() for p in parent_path)
            target_parent_id = path_index.get(parent_path) or path_index.get(lower_parent_path)

            # Próba alternatywnego znalezienia po parent_source_url
            if target_parent_id is None and item.parent_source_url and item.parent_source_url in category_mapping:
                target_parent_id = category_mapping[item.parent_source_url]

            if target_parent_id is None:
                err_msg = (
                    f"Nie odnaleziono rodzica dla kategorii '{item.name}' "
                    f"(ścieżka rodzica: {' > '.join(parent_path)}, parent_url: {item.parent_source_url})"
                )
                logger.warning(err_msg)
                stats.errors += 1
                stats.error_details.append(err_msg)
                continue

        # Sprawdzenie czy kategoria już istnieje w sklepie
        cat_key = item.path_key
        lower_cat_key = tuple(p.lower() for p in item.category_path)

        existing_cat_id = path_index.get(cat_key) or path_index.get(lower_cat_key)
        if existing_cat_id is None:
            # Sprawdzenie po (target_parent_id, nazwa)
            existing_cat_id = (
                parent_child_index.get((target_parent_id, item.name))
                or parent_child_index.get((target_parent_id, item.name.lower()))
            )

        if existing_cat_id is None and item.source_url in category_mapping:
            candidate_id = category_mapping[item.source_url]
            if candidate_id in existing_by_id:
                existing_cat_id = candidate_id

        cat_id: Optional[int] = None

        if existing_cat_id is not None:
            cat_id = existing_cat_id
            if update_existing:
                current = existing_by_id.get(cat_id)
                expected_slug = slugify(item.name)
                is_dirty = True
                if current is not None:
                    curr_name = current.get("name", "")
                    curr_parent = current.get("id_parent", 0)
                    curr_slug = current.get("link_rewrite", "")
                    curr_active = current.get("active", 1)

                    if (
                        curr_name == item.name
                        and curr_parent == target_parent_id
                        and curr_slug == expected_slug
                        and curr_active == 1
                    ):
                        is_dirty = False

                if not is_dirty:
                    stats.skipped += 1
                    logger.debug(
                        "Pominięto niezmienioną kategorię ID %d: %s (dirty-checking)",
                        cat_id,
                        item.name,
                    )
                else:
                    payload = build_category_payload(
                        item, target_parent_id, category_id=cat_id, active_languages=active_languages
                    )

                    try:
                        client.put("categories", cat_id, payload)
                        stats.updated += 1
                        logger.debug("Zaktualizowano kategorię ID %d: %s", cat_id, item.name)
                        existing_by_id[cat_id] = {
                            "id": cat_id,
                            "id_parent": target_parent_id,
                            "name": item.name,
                            "link_rewrite": expected_slug,
                            "active": 1,
                        }
                    except PrestaShopAPIError as exc:
                        err_msg = f"Błąd aktualizacji kategorii ID {cat_id} ('{item.name}'): {exc}"
                        logger.warning(err_msg)
                        stats.errors += 1
                        stats.error_details.append(err_msg)
            else:
                stats.skipped += 1
                logger.debug("Pominięto istniejącą kategorię ID %d: %s", cat_id, item.name)
        else:
            # Tworzenie brakującej kategorii
            payload = build_category_payload(item, target_parent_id, active_languages=active_languages)
            try:
                created = client.post("categories", payload)
                if not isinstance(created, dict) or "category" not in created or "id" not in created["category"]:
                    raise PrestaShopAPIError(f"Nieprawidłowa odpowiedź PrestaShop po utworzeniu kategorii: {created}")
                cat_id = int(created["category"]["id"])
                stats.created += 1
                logger.info("Utworzono nową kategorię ID %d: %s (rodzic ID: %d)", cat_id, item.name, target_parent_id)
                existing_by_id[cat_id] = {
                    "id": cat_id,
                    "id_parent": target_parent_id,
                    "name": item.name,
                    "link_rewrite": slugify(item.name),
                    "active": 1,
                }
            except PrestaShopAPIError as exc:
                err_msg = f"Błąd tworzenia kategorii '{item.name}': {exc}"
                logger.warning(err_msg)
                stats.errors += 1
                stats.error_details.append(err_msg)
                continue

        if cat_id is not None:
            # Rejestracja w indeksach na potrzeby kolejnych poziomów
            path_index[cat_key] = cat_id
            path_index[lower_cat_key] = cat_id
            parent_child_index[(target_parent_id, item.name)] = cat_id
            parent_child_index[(target_parent_id, item.name.lower())] = cat_id

            # Zapis do mapowania
            category_mapping[item.source_url] = cat_id
            formatted_path = item.formatted_path
            category_mapping[formatted_path] = cat_id
            slash_path = " / ".join(item.category_path)
            if slash_path != formatted_path:
                category_mapping[slash_path] = cat_id

    # 4. Zapisanie pliku mapowania
    category_mapping["home"] = PRESTASHOP_HOME_CATEGORY_ID
    category_mapping["Strona główna"] = PRESTASHOP_HOME_CATEGORY_ID
    save_category_mapping(mapping_path, category_mapping)

    return stats, category_mapping


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
        args.mapping_file if args.mapping_file else _PROJECT_ROOT / "scraper-results" / "category_mapping.json"
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


if __name__ == "__main__":
    sys.exit(main())
