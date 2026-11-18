from __future__ import annotations

import logging
from typing import Any, Dict, List, Optional, Set, Tuple

from prestashop_api import (
    PrestaShopClient,
    PrestaShopAuthError,
)
from .models import CategoryItem, PRESTASHOP_HOME_CATEGORY_ID
from .utils import slugify, _extract_category_name

logger = logging.getLogger("category_import")


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
    Zabezpieczone przed pętlami cyklicznymi w relacji rodzic-dziecko.
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
    existing_data: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    """
    Tworzy słownik danych dla PrestaShop Webservice API z obsługą wielojęzyczności
    dla wszystkich aktywnych języków oraz sluga w polu link_rewrite.
    W przypadku operacji PUT zachowuje istniejący opis oraz pozostałe pola, którymi importer nie zarządza.
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
    if existing_data and isinstance(existing_data, dict):
        unmanaged_fields = [
            "description",
            "meta_title",
            "meta_description",
            "meta_keywords",
            "is_root_category",
        ]
        for field_name in unmanaged_fields:
            val = existing_data.get(field_name)
            if val is not None and val != "":
                payload[field_name] = val

    return payload
