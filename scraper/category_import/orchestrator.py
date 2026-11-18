from __future__ import annotations

import logging
from pathlib import Path
from typing import Any, Dict, List, Optional, Set, Tuple, Union

from prestashop_api import (
    PrestaShopClient,
    PrestaShopAPIError,
)
from .models import CategoryItem, ImportStats, PRESTASHOP_HOME_CATEGORY_ID
from .utils import slugify
from .validator import load_and_validate_categories
from .mapping import save_category_mapping, load_category_mapping
from .prestashop_sync import (
    fetch_existing_categories,
    build_prestashop_hierarchy_index,
    fetch_active_languages,
    build_category_payload,
)

logger = logging.getLogger("category_import")

_CURRENT_DIR = Path(__file__).resolve().parent
_PROJECT_ROOT = _CURRENT_DIR.parent.parent


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
    validation_errors: List[str] = []
    items = load_and_validate_categories(categories_path, validation_errors=validation_errors)

    # 2. Pobranie aktywnych języków oraz istniejących kategorii w celu zachowania pełnej idempotencji
    active_languages = fetch_active_languages(client)
    logger.info("Aktywne języki PrestaShop: %s", ", ".join(active_languages))

    existing_categories = fetch_existing_categories(client)
    path_index, parent_child_index, existing_by_id = build_prestashop_hierarchy_index(existing_categories)

    stats = ImportStats(total=len(items) + len(validation_errors))
    if validation_errors:
        stats.errors += len(validation_errors)
        stats.error_details.extend(validation_errors)

    # Generowanie mapowania dla konkretnej instancji sklepu
    category_mapping: Dict[str, int] = {}
    assigned_id_to_url: Dict[int, str] = {}
    failed_parent_paths: Set[Tuple[str, ...]] = set()
    failed_parent_urls: Set[str] = set()

    existing_file_map: Dict[str, int] = {}
    if mapping_path.is_file():
        try:
            existing_file_map = load_category_mapping(mapping_path)
        except Exception as exc:
            logger.debug("Nie udało się załadować istniejącego pliku mapowania (%s): %s", mapping_path, exc)

    # 3. Przetwarzanie kategorii z zachowaniem kolejności rodzic -> dzieci
    for item in items:
        # Sprawdzenie czy rodzic lub przodek uległ awarii (pominięcie zależnych podkategorii)
        parent_path = tuple(item.category_path[:-1])
        is_parent_failed = (
            parent_path in failed_parent_paths
            or any(tuple(item.category_path[:i]) in failed_parent_paths for i in range(1, len(item.category_path)))
            or (item.parent_source_url is not None and item.parent_source_url in failed_parent_urls)
        )
        if is_parent_failed:
            err_msg = (
                f"Pominięto zależną podkategorię '{item.name}' (ścieżka: {item.formatted_path}) "
                f"ze względu na błąd zapisu/aktualizacji rodzica."
            )
            logger.warning(err_msg)
            stats.skipped += 1
            stats.errors += 1
            stats.error_details.append(err_msg)
            failed_parent_paths.add(item.path_key)
            failed_parent_urls.add(item.source_url)
            continue

        # Określenie ID rodzica w PrestaShop
        if item.depth == 1:
            target_parent_id = PRESTASHOP_HOME_CATEGORY_ID
        else:
            lower_parent_path = tuple(p.lower() for p in parent_path)
            target_parent_id = path_index.get(parent_path) or path_index.get(lower_parent_path)

            # Próba alternatywnego znalezienia po parent_source_url w wygenerowanej mapie
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
                failed_parent_paths.add(item.path_key)
                failed_parent_urls.add(item.source_url)
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

        # Weryfikacja zapisanego ID z pliku mapowania: czy należy do tej samej kategorii źródłowej
        if existing_cat_id is None and item.source_url in existing_file_map:
            candidate_id = existing_file_map[item.source_url]
            if candidate_id in existing_by_id:
                ps_cat = existing_by_id[candidate_id]
                ps_parent = ps_cat.get("id_parent", 0)
                ps_name = ps_cat.get("name", "").strip().lower()
                name_matches = ps_name == item.name.strip().lower()
                slug_matches = ps_cat.get("link_rewrite", "").strip() == slugify(item.name)

                # Sprawdzenie czy ID nie należy do innej kategorii z pliku źródłowego o tej nazwie (np. 'Klamki do drzwi')
                other_item_with_ps_name = any(
                    other.name.strip().lower() == ps_name and other.source_url != item.source_url
                    for other in items
                )

                if name_matches or slug_matches or (ps_parent == target_parent_id and not other_item_with_ps_name):
                    existing_cat_id = candidate_id
                else:
                    logger.warning(
                        "Zapisane ID %d w pliku mapowania nie należy do kategorii źródłowej '%s' "
                        "(w sklepie: '%s', rodzic: %d). Zignorowano niepoprawne powiązanie.",
                        candidate_id,
                        item.name,
                        ps_cat.get("name"),
                        ps_parent,
                    )

        # Sprawdzenie konfliktu: czy znalezione ID nie zostało już przypisane do innego source_url
        if existing_cat_id is not None and existing_cat_id in assigned_id_to_url:
            prev_url = assigned_id_to_url[existing_cat_id]
            if prev_url != item.source_url:
                err_msg = (
                    f"Konflikt mapowania: dwa różne source_url ('{prev_url}' oraz '{item.source_url}') "
                    f"wskazują na to samo ID kategorii {existing_cat_id} w PrestaShop!"
                )
                logger.error(err_msg)
                stats.errors += 1
                stats.error_details.append(err_msg)
                failed_parent_paths.add(item.path_key)
                failed_parent_urls.add(item.source_url)
                continue

        cat_id: Optional[int] = None
        api_success = False

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
                    api_success = True
                    logger.debug(
                        "Pominięto niezmienioną kategorię ID %d: %s (dirty-checking)",
                        cat_id,
                        item.name,
                    )
                else:
                    # Pobranie pełnego obiektu kategorii w celu zachowania opisu i pól niezarządzanych przez importer
                    try:
                        cat_resp = client.get("categories", cat_id)
                        existing_cat_full = (
                            cat_resp.get("category", cat_resp) if isinstance(cat_resp, dict) else {}
                        )
                    except Exception:
                        existing_cat_full = {}

                    payload = build_category_payload(
                        item,
                        target_parent_id,
                        category_id=cat_id,
                        active_languages=active_languages,
                        existing_data=existing_cat_full,
                    )

                    try:
                        client.put("categories", cat_id, payload)
                        stats.updated += 1
                        api_success = True
                        logger.debug("Zaktualizowano kategorię ID %d: %s (zachowano opis i pola)", cat_id, item.name)
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
                        failed_parent_paths.add(item.path_key)
                        failed_parent_urls.add(item.source_url)
                        continue
            else:
                stats.skipped += 1
                api_success = True
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
                api_success = True
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
                failed_parent_paths.add(item.path_key)
                failed_parent_urls.add(item.source_url)
                continue

        # Zapis nowej ścieżki w mapie i indeksach DOPIERO po sukcesie API
        if api_success and cat_id is not None:
            if cat_id in assigned_id_to_url and assigned_id_to_url[cat_id] != item.source_url:
                err_msg = (
                    f"Konflikt mapowania: kategorie '{item.name}' ({item.source_url}) oraz "
                    f"'{assigned_id_to_url[cat_id]}' wskazują na to samo ID {cat_id} w PrestaShop!"
                )
                logger.error(err_msg)
                stats.errors += 1
                stats.error_details.append(err_msg)
                continue

            assigned_id_to_url[cat_id] = item.source_url

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
