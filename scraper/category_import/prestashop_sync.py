from __future__ import annotations

import logging
from typing import Any, Dict, List, Optional, Set, Tuple

from prestashop_api import (
    PrestaShopAPIError,
    PrestaShopClient,
)

from .models import PRESTASHOP_HOME_CATEGORY_ID, CategoryItem
from .utils import _extract_category_name, slugify

logger = logging.getLogger("category_import")

_CATEGORY_MANAGED_FIELDS = ("name", "link_rewrite", "id_parent", "active")
_CATEGORY_REQUIRED_FIELDS = ("id", "name", "link_rewrite", "id_parent", "active")
_CATEGORY_TRANSLATED_FIELDS = (
    "description",
    "additional_description",
    "meta_title",
    "meta_description",
    "meta_keywords",
)
# PrestaShop sam aktualizuje date_upd i przelicza pola drzewa.
_CATEGORY_PRESERVED_FIELDS = (
    *_CATEGORY_TRANSLATED_FIELDS,
    "id_shop_default",
    "is_root_category",
    "position",
    "date_add",
)


def fetch_category_for_update(client: PrestaShopClient, category_id: int) -> Dict[str, Any]:
    response = client.get("categories", category_id)
    category = response.get("category", response) if isinstance(response, dict) else None
    if not isinstance(category, dict) or str(category.get("id")) != str(category_id):
        raise PrestaShopAPIError(f"API nie zwróciło kategorii ID {category_id}.")

    missing = set(_CATEGORY_REQUIRED_FIELDS) - category.keys()
    if missing:
        raise PrestaShopAPIError(
            f"Niepełne dane kategorii ID {category_id}. Brak pól: {', '.join(sorted(missing))}."
        )
    return category


def normalize_multilang_value(val: Any) -> Any:
    """
    Przekształca wartości wielojęzyczne z formatu odpowiedzi API PrestaShop
    (np. [{'id': '1', 'value': '...'}, ...] lub {'language': [...]})
    na słownik {id_jezyka: tekst}, poprawnie serializowany do <language id="...">
    przez dict_to_prestashop_xml w prestashop_api.py.
    """
    if val is None:
        return ""

    if isinstance(val, dict):
        # Sprawdzenie czy to już { "1": "tekst", "2": "tekst" }
        if val and all(str(k).isdigit() for k in val.keys()):
            return {str(k): (v if v is not None else "") for k, v in val.items()}
        if "language" in val:
            return normalize_multilang_value(val["language"])
        # Format z 'value' lub '#text' w słowniku
        res: Dict[str, str] = {}
        for k, v in val.items():
            if str(k).isdigit():
                res[str(k)] = str(v) if v is not None else ""
        if res:
            return res
        return val

    if isinstance(val, list):
        res: Dict[str, str] = {}
        for item in val:
            if isinstance(item, dict):
                lid = str(item.get("id") or item.get("@id") or "")
                text = item.get("value") or item.get("#text") or ""
                if lid and lid.isdigit():
                    res[lid] = str(text) if text is not None else ""
        if res:
            return res

    return val


def fetch_existing_categories(client: PrestaShopClient) -> List[Dict[str, Any]]:
    """
    Pobiera listę istniejących kategorii ze sklepu PrestaShop Webservice API
    z polami id, id_parent, name, link_rewrite oraz active.
    """
    logger.info("Pobieranie istniejących kategorii z PrestaShop API (display=[id,id_parent,name,link_rewrite,active])...")
    res = client.get("categories", params={"display": "[id,id_parent,name,link_rewrite,active]"})

    categories = resource_records(res, "categories", "category")
    cleaned: List[Dict[str, Any]] = []
    seen = set()
    for cat in categories:
        try:
            if set(_CATEGORY_REQUIRED_FIELDS) - cat.keys():
                raise ValueError("Brak wymaganych pól kategorii")
            cid, pid, active = int(cat["id"]), int(cat["id_parent"]), int(cat["active"])
            name = _extract_category_name(cat["name"])
            slug = _extract_category_name(cat["link_rewrite"])
            if cid <= 0 or pid < 0 or active not in {0, 1} or not name or cid in seen:
                raise ValueError("Niepoprawne lub powielone dane kategorii")
            cleaned.append({"id": cid, "id_parent": pid, "name": name,
                            "link_rewrite": slug, "active": active})
            seen.add(cid)
        except (TypeError, ValueError, KeyError) as exc:
            raise PrestaShopAPIError("Niepełna lub niepoprawna lista kategorii API; zapis zablokowany.") from exc

    logger.info("Znaleziono %d istniejących kategorii w PrestaShop.", len(cleaned))
    return cleaned


def build_prestashop_hierarchy_index(
    existing_categories: List[Dict[str, Any]],
) -> Tuple[Dict[Tuple[str, ...], Optional[int]], Dict[Tuple[int, str], Optional[int]], Dict[int, Dict[str, Any]]]:
    """
    Buduje indeksy wyszukiwania istniejących kategorii:
    1. path_index: mapowanie pełnej ścieżki od korzenia (id=2) na ID kategorii.
    2. parent_child_index: mapowanie (id_parent, name_lowercase) na ID kategorii.
    3. existing_by_id: słownik istniejących kategorii indeksowany po ID kategorii.
    Zabezpieczone przed pętlami cyklicznymi w relacji rodzic-dziecko.
    """
    existing_by_id: Dict[int, Dict[str, Any]] = {c["id"]: c for c in existing_categories}
    path_index: Dict[Tuple[str, ...], Optional[int]] = {}
    parent_child_index: Dict[Tuple[int, str], Optional[int]] = {}

    for c in existing_categories:
        cid = c["id"]
        pid = c["id_parent"]
        name = c["name"]
        for key in {(pid, name.lower()), (pid, name)}:
            if key in parent_child_index and parent_child_index[key] != cid:
                parent_child_index[key] = None
            else:
                parent_child_index[key] = cid

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
            for key in {full_path, tuple(p.lower() for p in full_path)}:
                if key in path_index and path_index[key] != cid:
                    path_index[key] = None
                else:
                    path_index[key] = cid

    return path_index, parent_child_index, existing_by_id


def fetch_active_languages(client: PrestaShopClient) -> List[str]:
    """
    Pobiera identyfikatory wszystkich aktywnych języków w sklepie PrestaShop.
    Brak uprawnień lub błędna odpowiedź blokuje zapis zamiast zgadywać język.
    """
    res = client.get("languages", params={"display": "[id]", "filter[active]": "1"})
    languages = resource_records(res, "languages", "language")
    ids = [str(record.get("id", "")) for record in languages]
    if not ids or any(not value.isdigit() or int(value) <= 0 for value in ids) or len(set(ids)) != len(ids):
        raise PrestaShopAPIError("Brak poprawnej listy aktywnych języków; zapis zablokowany.")
    return ids


def resource_records(response: Any, plural: str, singular: str) -> List[Dict[str, Any]]:
    """Nie myli błędu protokołu z pustym wynikiem. PS zwraca {} dla pustych list."""
    if not isinstance(response, dict) or (response and plural not in response):
        raise PrestaShopAPIError(f"Niepoprawna odpowiedź listy {plural}.")
    raw = response.get(plural, [])
    if isinstance(raw, dict) and singular in raw:
        raw = raw[singular]
        if isinstance(raw, dict):
            raw = [raw]
    if not isinstance(raw, list) or any(not isinstance(record, dict) for record in raw):
        raise PrestaShopAPIError(f"Niepoprawna struktura listy {plural}.")
    return raw


def build_category_payload(
    item: CategoryItem,
    parent_id: int,
    category_id: Optional[int] = None,
    active_languages: Optional[List[str]] = None,
    existing_data: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    """
    Tworzy słownik danych dla PrestaShop Webservice API z obsługą wielojęzyczności
    dla wszystkich aktywnych języków oraz sluga w polu link_rewrite.
    W przypadku operacji PUT zachowuje istniejący opis, dodatkowy opis oraz pozostałe
    pola, którymi importer nie zarządza, poprawnie formatując pola wielojęzyczne.
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

    # Zachowanie istniejącego opisu i pozostałych pól, którymi importer nie zarządza (dla PUT)
    if existing_data:
        for field in _CATEGORY_PRESERVED_FIELDS:
            if field in existing_data:
                value = existing_data[field]
                if field in _CATEGORY_TRANSLATED_FIELDS:
                    value = normalize_multilang_value(value)
                    if isinstance(value, str):
                        value = {langs[0]: value}
                payload[field] = value

    return payload
