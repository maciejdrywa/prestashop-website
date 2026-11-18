from __future__ import annotations

import logging
from pathlib import Path
from typing import Dict, List, Optional, Set, Tuple, Union

from prestashop_api import (
    PrestaShopAPIError,
    PrestaShopClient,
)

from .mapping import (
    get_default_mapping_path,
    load_category_mapping,
    save_category_mapping,
)
from .models import PRESTASHOP_HOME_CATEGORY_ID, ImportStats
from .prestashop_sync import (
    build_category_payload,
    build_prestashop_hierarchy_index,
    fetch_active_languages,
    fetch_category_for_update,
    fetch_existing_categories,
)
from .utils import slugify
from .validator import load_and_validate_categories

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
    - mapping_file: ścieżka do category_mapping.json (domyślnie dobierana per instancja)
    - update_existing: czy aktualizować istniejące kategorie (PUT), czy je tylko pominąć

    Zwraca:
    - (ImportStats, category_mapping_dict)
    """
    categories_path = (
        Path(categories_file) if categories_file else _PROJECT_ROOT / "scraper-results" / "categories.json"
    )

    if client is None:
        client = PrestaShopClient()

    # 1. Dobór pliku mapy wg instancji sklepu, o ile użytkownik nie podał explicite mapping_file
    if mapping_file:
        mapping_path = Path(mapping_file)
    else:
        mapping_path = get_default_mapping_path(
            _PROJECT_ROOT / "scraper-results",
            getattr(client, "api_url", None),
        )

    # 2. Wczytanie i walidacja danych ze scrapowania
    validation_errors: List[str] = []
    items = load_and_validate_categories(categories_path, validation_errors=validation_errors)

    # 3. Pobranie aktywnych języków oraz istniejących kategorii w celu zachowania pełnej idempotencji
    active_languages = fetch_active_languages(client)
    logger.info("Aktywne języki PrestaShop: %s", ", ".join(active_languages))

    existing_categories = fetch_existing_categories(client)
    path_index, parent_child_index, existing_by_id = build_prestashop_hierarchy_index(existing_categories)

    stats = ImportStats(total=len(items) + len(validation_errors))
    if validation_errors:
        stats.errors += len(validation_errors)
        stats.error_details.extend(validation_errors)

    # Wczytanie i weryfikacja instancji istniejącego pliku mapowania
    existing_file_map: Dict[str, int] = {}
    if mapping_path.is_file():
        try:
            existing_file_map = load_category_mapping(
                mapping_path,
                expected_instance_url=getattr(client, "api_url", None),
            )
        except Exception as exc:
            logger.debug("Nie udało się załadować istniejącego pliku mapowania (%s): %s", mapping_path, exc)

    # Wykrywanie kolizji w starej mapie (różne URL-e wskazujące na to samo ID PrestaShop)
    id_to_first_url: Dict[int, str] = {}
    file_map_collisions: List[str] = []
    collided_ids: Set[int] = set()

    for k, cid in existing_file_map.items():
        if k.startswith("http://") or k.startswith("https://"):
            if cid in id_to_first_url and id_to_first_url[cid] != k:
                collision_msg = (
                    f"Wykryto kolizję w pliku mapowania: dwa różne source_url "
                    f"('{id_to_first_url[cid]}' oraz '{k}') wskazują na to samo ID kategorii {cid} w PrestaShop!"
                )
                logger.error(collision_msg)
                file_map_collisions.append(collision_msg)
                collided_ids.add(cid)
            else:
                id_to_first_url[cid] = k

    if file_map_collisions:
        stats.errors += len(file_map_collisions)
        stats.error_details.extend(file_map_collisions)
        # Usunięcie ze starej mapy skolidowanych ID, aby zapobiec nadpisaniu obcej kategorii
        existing_file_map = {k: v for k, v in existing_file_map.items() if v not in collided_ids}

    # Weryfikacja zapisanych ścieżek na podstawie drzewa sprzed jakichkolwiek zmian.
    verified_ids = {
        cid
        for path, cid in path_index.items()
        if existing_file_map.get(" > ".join(path)) == cid
        or existing_file_map.get(" / ".join(path)) == cid
    }

    # Generowanie mapowania dla konkretnej instancji sklepu
    category_mapping: Dict[str, int] = {}
    assigned_id_to_url: Dict[int, str] = {}
    failed_parent_paths: Set[Tuple[str, ...]] = set()
    failed_parent_urls: Set[str] = set()

    # 4. Przetwarzanie kategorii z zachowaniem kolejności rodzic -> dzieci
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
            # Zachowanie dotychczasowych wpisów podkategorii ze starej mapy
            if item.source_url in existing_file_map:
                old_id = existing_file_map[item.source_url]
                category_mapping[item.source_url] = old_id
                for k, v in existing_file_map.items():
                    if v == old_id and not str(k).startswith("_"):
                        category_mapping[k] = v
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
                # Zachowanie dotychczasowych wpisów podkategorii ze starej mapy
                if item.source_url in existing_file_map:
                    old_id = existing_file_map[item.source_url]
                    category_mapping[item.source_url] = old_id
                    for k, v in existing_file_map.items():
                        if v == old_id and not str(k).startswith("_"):
                            category_mapping[k] = v
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

        candidate_id = existing_file_map.get(item.source_url)
        conflict = None
        if candidate_id is not None and candidate_id in verified_ids:
            if existing_cat_id is None or existing_cat_id == candidate_id:
                existing_cat_id = candidate_id
            else:
                conflict = (
                    f"Konflikt mapowania: source_url '{item.source_url}' ma zapisane ID {candidate_id}, "
                    f"a docelową ścieżkę '{item.formatted_path}' zajmuje ID {existing_cat_id}."
                )
        elif candidate_id is not None and candidate_id != existing_cat_id:
            logger.warning(
                "Zignorowano zapisane ID %d dla source_url '%s'. "
                "Poprzednia ścieżka w mapie nie odpowiada kategorii w sklepie.",
                candidate_id,
                item.source_url,
            )

        if existing_cat_id is not None and conflict is None:
            prev_url = assigned_id_to_url.get(existing_cat_id)
            if prev_url is None and existing_cat_id in verified_ids:
                prev_url = id_to_first_url.get(existing_cat_id)
            if prev_url is not None and prev_url != item.source_url:
                conflict = (
                    f"Konflikt mapowania: dwa różne source_url ('{prev_url}' oraz '{item.source_url}') "
                    f"wskazują na to samo ID kategorii {existing_cat_id} w PrestaShop!"
                )

        if conflict is not None:
            logger.error(conflict)
            stats.errors += 1
            stats.error_details.append(conflict)
            failed_parent_paths.add(item.path_key)
            failed_parent_urls.add(item.source_url)
            # Zachowaj dotychczasowe powiązania stron konfliktu, których nie przetworzono.
            unchanged_ids = {candidate_id, existing_cat_id} & (verified_ids - assigned_id_to_url.keys())
            category_mapping.update({key: cid for key, cid in existing_file_map.items() if cid in unchanged_ids})
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
                        existing_cat_full = fetch_category_for_update(client, cat_id)
                    except PrestaShopAPIError as exc:
                        err_msg = (
                            f"Błąd pobierania pełnych danych kategorii ID {cat_id} ('{item.name}') przed PUT: {exc}. "
                            f"Pominięto aktualizację w celu ochrony przed wyczyszczeniem opisu."
                        )
                        logger.error(err_msg)
                        stats.errors += 1
                        stats.error_details.append(err_msg)
                        failed_parent_paths.add(item.path_key)
                        failed_parent_urls.add(item.source_url)
                        # Zachowanie dotychczasowych wpisów rodzica z existing_file_map
                        if item.source_url in existing_file_map:
                            old_id = existing_file_map[item.source_url]
                            category_mapping[item.source_url] = old_id
                            for k, v in existing_file_map.items():
                                if v == old_id and not str(k).startswith("_"):
                                    category_mapping[k] = v
                        continue

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

                        # Zachowanie dotychczasowych wpisów rodzica z existing_file_map
                        if item.source_url in existing_file_map:
                            old_id = existing_file_map[item.source_url]
                            category_mapping[item.source_url] = old_id
                            for k, v in existing_file_map.items():
                                if v == old_id and not str(k).startswith("_"):
                                    category_mapping[k] = v
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

    # 5. Zapisanie pliku mapowania wraz z metadanymi instancji sklepu
    category_mapping["home"] = PRESTASHOP_HOME_CATEGORY_ID
    category_mapping["Strona główna"] = PRESTASHOP_HOME_CATEGORY_ID
    save_category_mapping(
        mapping_path,
        category_mapping,
        instance_url=getattr(client, "api_url", None),
    )

    return stats, category_mapping
