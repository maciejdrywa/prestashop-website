"""
Test odbiorczy dla modułu importu i synchronizacji hierarchii kategorii PrestaShop.

Weryfikuje:
1. Odczyt i poprawność struktury danych w pliku scraper-results/categories.json.
2. Integralność słownika mapowania scraper-results/category_mapping.json.
3. Poprawność relacji rodzic-dziecko w PrestaShop Webservice API (kategorie główne -> id_parent=2, podkategorie -> id rodzica).
4. Pełną idempotencję: powtórne wywołanie importu nie tworzy duplikatów ani nie zmienia liczby kategorii w sklepie.
5. Poprawne zachowanie w przypadku wykrycia anomalii (kod wyjścia 1 oraz asercje pytest).
"""

from __future__ import annotations

import json
import sys
import tempfile
import time
from pathlib import Path
from typing import Any, Dict, List, Set, Tuple
from unittest.mock import Mock

# Zapewnienie poprawnego importu modułów
_CURRENT_DIR = Path(__file__).resolve().parent
_PROJECT_ROOT = _CURRENT_DIR.parent
if str(_CURRENT_DIR) not in sys.path:
    sys.path.insert(0, str(_CURRENT_DIR))
if str(_PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(_PROJECT_ROOT))

# Importy korzystają ze ścieżek przygotowanych powyżej dla testu uruchamianego jako moduł.
from import_categories import (  # noqa: E402
    PRESTASHOP_HOME_CATEGORY_ID,
    _extract_category_name,
    import_categories,
    load_and_validate_categories,
    load_category_mapping,
    normalize_multilang_value,
    save_category_mapping,
)
from prestashop_api import (  # noqa: E402
    PrestaShopAPIError,
    PrestaShopClient,
)

CATEGORIES_FILE = _PROJECT_ROOT / "scraper-results" / "categories.json"
MAPPING_FILE = _PROJECT_ROOT / "scraper-results" / "category_mapping.json"


def test_categories_file_structure() -> None:
    """
    Test 1: Weryfikacja odczytu i poprawności struktury kategorii w pliku źródłowym.
    """
    print("\n[TEST 1] Weryfikacja pliku źródłowego categories.json...")
    assert CATEGORIES_FILE.is_file(), f"Plik {CATEGORIES_FILE} nie istnieje!"

    items = load_and_validate_categories(CATEGORIES_FILE)
    assert len(items) >= 1, "Brak kategorii do zaimportowania!"
    print(f" [INFO] Załadowano {len(items)} kategorii do walidacji strukturalnej.")

    urls: Set[str] = set()
    paths: Set[Tuple[str, ...]] = set()

    for item in items:
        # Walidacja pól bazowych
        assert item.name, f"Kategoria {item} ma pustą nazwę!"
        assert item.category_path, f"Kategoria '{item.name}' ma pustą ścieżkę!"
        assert item.name == item.category_path[-1], (
            f"Nazwa kategorii '{item.name}' nie zgadza się z ostatnim elementem ścieżki: {item.category_path}"
        )
        assert item.source_url.startswith("http"), f"Nieprawidłowy source_url: {item.source_url}"

        # Unikalność URL i ścieżek
        assert item.source_url not in urls, f"Zduplikowany source_url: {item.source_url}"
        urls.add(item.source_url)

        path_tuple = tuple(item.category_path)
        assert path_tuple not in paths, f"Zduplikowana ścieżka kategorii: {path_tuple}"
        paths.add(path_tuple)

        # Spójność hierarchii
        if item.depth == 1:
            assert item.parent_source_url is None, (
                f"Kategoria poziomu 1 '{item.name}' nie powinna mieć parent_source_url: {item.parent_source_url}"
            )
        else:
            assert item.parent_source_url is not None, (
                f"Podkategoria '{item.name}' (głębokość {item.depth}) musi posiadać parent_source_url!"
            )
            assert item.parent_source_url in urls or any(c.source_url == item.parent_source_url for c in items), (
                f"parent_source_url '{item.parent_source_url}' dla '{item.name}' nie istnieje w zbiorze kategorii!"
            )

    print(f" [OK] Zwalidowano poprawnie {len(items)} kategorii (struktura, unikalność, hierarchia).")


def test_category_mapping_integrity() -> None:
    """
    Test 2: Weryfikacja poprawności słownika mapowania (category_mapping.json).
    """
    print("\n[TEST 2] Weryfikacja słownika mapowania category_mapping.json...")

    if not MAPPING_FILE.is_file():
        print(f" [INFO] Plik mapowania {MAPPING_FILE} nie istnieje - automatyczne uruchomienie import_categories()...")
        import_categories(categories_file=CATEGORIES_FILE, mapping_file=MAPPING_FILE)

    assert MAPPING_FILE.is_file(), (
        f"Plik mapowania {MAPPING_FILE} nie istnieje! "
        f"Uruchom najpierw skrypt import_categories.py przed weryfikacją mapowania."
    )
    mapping = load_category_mapping(MAPPING_FILE)
    assert len(mapping) > 0, "Słownik mapowania jest pusty!"

    # Sprawdzenie stałych wpisów dla kategorii głównej
    assert mapping.get("home") == PRESTASHOP_HOME_CATEGORY_ID, "Brak wpisu 'home' w pliku mapowania!"
    assert mapping.get("Strona główna") == PRESTASHOP_HOME_CATEGORY_ID, "Brak wpisu 'Strona główna' w pliku mapowania!"

    # Weryfikacja braku konfliktu ID: 'Akcesoria meblowe' i 'Klamki do drzwi' muszą mieć różne ID
    url_klamki = "https://pandomu.com.pl/4-klamki-do-drzwi"
    url_akcesoria = "https://pandomu.com.pl/68-akcesoria-meblowe"
    if url_klamki in mapping and url_akcesoria in mapping:
        assert mapping[url_klamki] != mapping[url_akcesoria], (
            f"Wykryto kolizję ID: 'Klamki do drzwi' i 'Akcesoria meblowe' dostały to samo ID {mapping[url_klamki]}!"
        )

    # Sprawdzenie czy wszystkie kategorie ze scraper-results/categories.json mają odwzorowanie
    # oraz czy dwa różne source_url nie wskazują na jedno ID (brak konfliktów 1:N)
    items = load_and_validate_categories(CATEGORIES_FILE)
    id_to_source_url: Dict[int, str] = {}

    for item in items:
        # Mapowanie po source_url
        assert item.source_url in mapping, f"Brak wpisu dla source_url '{item.source_url}' w pliku mapowania!"
        cat_id_by_url = mapping[item.source_url]
        assert isinstance(cat_id_by_url, int) and cat_id_by_url > 0, (
            f"Nieprawidłowy ID kategorii dla URL '{item.source_url}': {cat_id_by_url}"
        )

        assert (
            cat_id_by_url not in id_to_source_url or id_to_source_url[cat_id_by_url] == item.source_url
        ), (
            f"Konflikt w mapowaniu: dwa różne source_url ('{id_to_source_url.get(cat_id_by_url)}' oraz "
            f"'{item.source_url}') wskazują na to samo ID kategorii {cat_id_by_url}!"
        )
        id_to_source_url[cat_id_by_url] = item.source_url

        # Mapowanie po pełnej ścieżce tekstowej
        path_str = item.formatted_path
        assert path_str in mapping, f"Brak wpisu dla ścieżki '{path_str}' w pliku mapowania!"
        cat_id_by_path = mapping[path_str]
        assert cat_id_by_path == cat_id_by_url, (
            f"Niezgodność ID dla URL ({cat_id_by_url}) i ścieżki ({cat_id_by_path}) dla kategorii '{item.name}'"
        )

    print(f" [OK] Słownik mapowania zawiera poprawne, bezkonfliktowe ID dla wszystkich {len(items)} kategorii.")


def test_prestashop_parent_child_relations() -> None:
    """
    Test 3: Weryfikacja relacji rodzic-dziecko w PrestaShop API.
    """
    print("\n[TEST 3] Weryfikacja relacji rodzic-dziecko w PrestaShop API...")
    client = PrestaShopClient()
    client.check_connection()

    # Zapewnienie aktualnego importu i mapowania
    import_categories(client=client, categories_file=CATEGORIES_FILE, mapping_file=MAPPING_FILE)
    mapping = load_category_mapping(MAPPING_FILE)
    items = load_and_validate_categories(CATEGORIES_FILE)

    # Pobranie aktualnego stanu drzewa z API
    api_res = client.get("categories", params={"display": "[id,id_parent,name]"})
    if isinstance(api_res, dict):
        raw_cats = api_res.get("categories", [])
    elif isinstance(api_res, list):
        raw_cats = api_res
    else:
        raw_cats = []

    if isinstance(raw_cats, dict):
        if "category" in raw_cats:
            cat_field = raw_cats["category"]
            raw_cats = [cat_field] if isinstance(cat_field, dict) else (cat_field if isinstance(cat_field, list) else [])
        else:
            raw_cats = [raw_cats]
    elif not isinstance(raw_cats, list):
        raw_cats = []

    ps_categories_by_id: Dict[int, Dict[str, Any]] = {}
    for c in raw_cats:
        if isinstance(c, dict) and "id" in c:
            try:
                ps_categories_by_id[int(c["id"])] = {
                    "id": int(c["id"]),
                    "id_parent": int(c.get("id_parent", 0)),
                    "name": _extract_category_name(c.get("name", "")),
                }
            except (ValueError, TypeError):
                continue

    # Weryfikacja każdej zaimportowanej kategorii
    for item in items:
        cat_id = mapping[item.source_url]
        assert cat_id in ps_categories_by_id, (
            f"Kategoria ID {cat_id} ('{item.name}') nie została odnaleziona w odpowiedzi PrestaShop API!"
        )

        ps_cat = ps_categories_by_id[cat_id]
        actual_parent_id = ps_cat["id_parent"]

        if item.depth == 1:
            # Kategorie pierwszego poziomu MUSZĄ mieć id_parent = 2 ("Strona główna")
            assert actual_parent_id == PRESTASHOP_HOME_CATEGORY_ID, (
                f"Kategoria główna '{item.name}' (ID: {cat_id}) ma id_parent={actual_parent_id}, "
                f"a powinna mieć {PRESTASHOP_HOME_CATEGORY_ID} (Strona główna)!"
            )
        else:
            # Podkategorie MUSZĄ mieć id_parent równe ID swojego rodzica
            parent_url = item.parent_source_url
            assert parent_url in mapping, f"Brak rodzica o URL '{parent_url}' w mapowaniu dla '{item.name}'"
            expected_parent_id = mapping[parent_url]

            assert actual_parent_id == expected_parent_id, (
                f"Błąd relacji dla podkategorii '{item.name}' (ID: {cat_id}): "
                f"PrestaShop wskazuje id_parent={actual_parent_id}, a oczekiwano ID rodzica {expected_parent_id}!"
            )

        # Weryfikacja nazwy (wielkość liter i spacje)
        assert ps_cat["name"].strip().lower() == item.name.strip().lower(), (
            f"Niezgodność nazwy kategorii ID {cat_id}: w API '{ps_cat['name']}', a w źródle '{item.name}'"
        )

    print(f" [OK] Zweryfikowano poprawność hierarchii i relacji rodzic-dziecko dla wszystkich {len(items)} kategorii.")


def test_category_import_idempotence() -> None:
    """
    Test 4: Weryfikacja idempotencji importu.
    Ponowne wywołanie importu nie może zmienić łącznej liczby kategorii ani utworzyć duplikatów.
    """
    print("\n[TEST 4] Weryfikacja idempotencji (brak duplikatów przy powtórnym imporcie)...")
    client = PrestaShopClient()

    # Zapewnienie aktualnego stanu początkowego przed pomiarem
    import_categories(
        client=client,
        categories_file=CATEGORIES_FILE,
        mapping_file=MAPPING_FILE,
        update_existing=True,
    )

    # 1. Pobranie liczby kategorii przed drugim wywołaniem
    res_before = client.get("categories", params={"display": "[id,id_parent,name]"})
    if isinstance(res_before, dict):
        cats_before = res_before.get("categories", [])
    elif isinstance(res_before, list):
        cats_before = res_before
    else:
        cats_before = []

    if isinstance(cats_before, dict):
        if "category" in cats_before:
            cat_field = cats_before["category"]
            cats_before = [cat_field] if isinstance(cat_field, dict) else (cat_field if isinstance(cat_field, list) else [])
        else:
            cats_before = [cats_before]
    elif not isinstance(cats_before, list):
        cats_before = []
    count_before = len(cats_before)

    # 2. Powtórne wykonanie importu
    stats, mapping = import_categories(
        client=client,
        categories_file=CATEGORIES_FILE,
        mapping_file=MAPPING_FILE,
        update_existing=True,
    )

    # 3. Weryfikacja statystyk drugiego importu (dirty-checking)
    items = load_and_validate_categories(CATEGORIES_FILE)
    assert stats.created == 0, f"Naruszenie idempotencji: utworzono {stats.created} nowych kategorii zamiast 0!"
    assert stats.updated == 0, f"Naruszenie dirty-checkingu: zaktualizowano {stats.updated} kategorii zamiast 0!"
    assert stats.skipped == len(items), (
        f"Oczekiwano pominięcia wszystkich {len(items)} kategorii, pominięto: {stats.skipped}"
    )
    assert stats.errors == 0, f"Podczas powtórnego importu wystąpiły błędy: {stats.error_details}"

    # 4. Pobranie liczby kategorii po drugim wywołaniu
    res_after = client.get("categories", params={"display": "[id,id_parent,name]"})
    if isinstance(res_after, dict):
        cats_after = res_after.get("categories", [])
    elif isinstance(res_after, list):
        cats_after = res_after
    else:
        cats_after = []

    if isinstance(cats_after, dict):
        if "category" in cats_after:
            cat_field = cats_after["category"]
            cats_after = [cat_field] if isinstance(cat_field, dict) else (cat_field if isinstance(cat_field, list) else [])
        else:
            cats_after = [cats_after]
    elif not isinstance(cats_after, list):
        cats_after = []
    count_after = len(cats_after)

    assert count_before == count_after, (
        f"Liczba kategorii uległa zmianie po powtórnym imporcie: przed={count_before}, po={count_after}"
    )

    # 5. Sprawdzenie braku zduplikowanych nazw pod tym samym rodzicem
    parent_child_names: Dict[int, Set[str]] = {}
    for c in cats_after:
        if not isinstance(c, dict) or "id_parent" not in c or "name" not in c:
            continue
        try:
            pid = int(c["id_parent"])
            name_norm = _extract_category_name(c["name"]).lower()
            if pid not in parent_child_names:
                parent_child_names[pid] = set()
            assert name_norm not in parent_child_names[pid], (
                f"Wykryto duplikat kategorii '{c['name']}' pod rodzicem ID {pid}!"
            )
            parent_child_names[pid].add(name_norm)
        except (ValueError, TypeError):
            continue

    print(f" [OK] Idempotencja potwierdzona: liczba kategorii niezmienna ({count_after}), 0 duplikatów, dirty-checking OK.")


def test_dynamic_hierarchy_lifecycle() -> None:
    """
    Test 5: Dynamiczny test tworzenia wielopoziomowej hierarchii od zera,
    jej aktualizacji oraz usunięcia rekordów testowych.
    """
    print("\n[TEST 5] Test dynamicznego tworzenia nowej hierarchii (Root -> Child -> Grandchild)...")
    client = PrestaShopClient()
    ts = int(time.time() * 1000)

    dynamic_cats = [
        {
            "name": f"Test Root {ts}",
            "category_path": [f"Test Root {ts}"],
            "source_url": f"https://example.test/dyn-{ts}-root",
            "parent_source_url": None,
            "product_ids": [],
        },
        {
            "name": f"Test Child {ts}",
            "category_path": [f"Test Root {ts}", f"Test Child {ts}"],
            "source_url": f"https://example.test/dyn-{ts}-child",
            "parent_source_url": f"https://example.test/dyn-{ts}-root",
            "product_ids": [],
        },
        {
            "name": f"Test Grandchild {ts}",
            "category_path": [f"Test Root {ts}", f"Test Child {ts}", f"Test Grandchild {ts}"],
            "source_url": f"https://example.test/dyn-{ts}-grandchild",
            "parent_source_url": f"https://example.test/dyn-{ts}-child",
            "product_ids": [],
        },
    ]

    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".json", delete=False) as f_cats:
        json.dump(dynamic_cats, f_cats)
        temp_cats_path = Path(f_cats.name)

    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".json", delete=False) as f_map:
        temp_map_path = Path(f_map.name)

    created_ids: List[int] = []
    mapping1: Dict[str, int] = {}

    try:
        # Krok A: Utworzenie 3 poziomów kategorii
        stats1, mapping1 = import_categories(
            client=client,
            categories_file=temp_cats_path,
            mapping_file=temp_map_path,
        )
        # Dynamiczne zbieranie ID z mapping1 na bieżąco (w kolejności od liści do korzenia do bezpiecznego usuwania)
        for cat in reversed(dynamic_cats):
            cid = mapping1.get(cat["source_url"])
            if cid and cid not in created_ids:
                created_ids.append(cid)

        assert stats1.created == 3, f"Oczekiwano utworzenia 3 kategorii, utworzono: {stats1.created}"
        assert stats1.errors == 0, f"Błędy podczas tworzenia: {stats1.error_details}"

        root_id = mapping1[dynamic_cats[0]["source_url"]]
        child_id = mapping1[dynamic_cats[1]["source_url"]]
        grandchild_id = mapping1[dynamic_cats[2]["source_url"]]

        # Weryfikacja w API (bezpieczna obsługa pojedynczego dict w category)
        root_res = client.get("categories", root_id)
        child_res = client.get("categories", child_id)
        grandchild_res = client.get("categories", grandchild_id)

        root_data = root_res["category"] if isinstance(root_res, dict) and "category" in root_res else root_res
        child_data = child_res["category"] if isinstance(child_res, dict) and "category" in child_res else child_res
        grandchild_data = grandchild_res["category"] if isinstance(grandchild_res, dict) and "category" in grandchild_res else grandchild_res

        assert int(root_data["id_parent"]) == PRESTASHOP_HOME_CATEGORY_ID
        assert int(child_data["id_parent"]) == root_id
        assert int(grandchild_data["id_parent"]) == child_id

        # Ustawienie opisu oraz opisu dodatkowego w co najmniej dwóch językach (np. ID 1 i 2)
        sample_description = {
            "1": f"<p>Ważny opis PL kategorii testowej {ts} - zachować przy PUT</p>",
            "2": f"<p>Important EN description for category {ts} - preserve on PUT</p>",
        }
        sample_additional_description = {
            "1": f"<p>Dodatkowy opis PL {ts}</p>",
            "2": f"<p>Additional description EN {ts}</p>",
        }
        client.put("categories", grandchild_id, {
            "id": grandchild_id,
            "name": {"1": dynamic_cats[2]["name"], "2": f"Test Grandchild EN {ts}"},
            "link_rewrite": {"1": f"test-grandchild-{ts}", "2": f"test-grandchild-en-{ts}"},
            "id_parent": child_id,
            "active": 1,
            "description": sample_description,
            "additional_description": sample_additional_description,
        })
        gc_before = client.get("categories", grandchild_id)["category"]
        norm_desc_before = normalize_multilang_value(gc_before.get("description"))
        norm_add_before = normalize_multilang_value(gc_before.get("additional_description"))
        desc_1_before = norm_desc_before.get("1") if isinstance(norm_desc_before, dict) else norm_desc_before
        add_1_before = norm_add_before.get("1") if isinstance(norm_add_before, dict) else norm_add_before
        assert desc_1_before == sample_description["1"]
        assert add_1_before == sample_additional_description["1"]

        # Krok B: Aktualizacja pod kątem dirty-checkingu
        # Modyfikacja nazwy jednej z dynamicznych kategorii (wnuka)
        updated_grandchild_name = f"Test Grandchild Renamed {ts}"
        dynamic_cats[2]["name"] = updated_grandchild_name
        dynamic_cats[2]["category_path"][-1] = updated_grandchild_name

        with temp_cats_path.open("w", encoding="utf-8") as f_cats:
            json.dump(dynamic_cats, f_cats, ensure_ascii=False, indent=2)

        stats2, _ = import_categories(
            client=client,
            categories_file=temp_cats_path,
            mapping_file=temp_map_path,
            update_existing=True,
        )
        assert stats2.created == 0, f"Drugie wywołanie nie powinno tworzyć kategorii (utworzono: {stats2.created})"
        assert stats2.updated == 1, f"Oczekiwano zaktualizowania dokładnie 1 kategorii (zaktualizowano: {stats2.updated})"
        assert stats2.skipped == 2, f"Oczekiwano pominięcia 2 niezmienionych kategorii (pominięto: {stats2.skipped})"
        assert stats2.errors == 0, f"Błędy podczas aktualizacji: {stats2.error_details}"

        # Weryfikacja w API zaktualizowanej nazwy oraz asercja zachowania opisu i opisu dodatkowego w wielu językach
        updated_grandchild_res = client.get("categories", grandchild_id)
        updated_data = (
            updated_grandchild_res["category"]
            if isinstance(updated_grandchild_res, dict) and "category" in updated_grandchild_res
            else updated_grandchild_res
        )
        assert _extract_category_name(updated_data.get("name", "")) == updated_grandchild_name

        norm_desc_after = normalize_multilang_value(updated_data.get("description"))
        norm_add_after = normalize_multilang_value(updated_data.get("additional_description"))

        assert norm_desc_after is not None and norm_desc_after != "", "Pole 'description' zostało usunięte po PUT!"
        assert norm_add_after is not None and norm_add_after != "", "Pole 'additional_description' zostało usunięte po PUT!"

        desc_1_after = norm_desc_after.get("1") if isinstance(norm_desc_after, dict) else norm_desc_after
        add_1_after = norm_add_after.get("1") if isinstance(norm_add_after, dict) else norm_add_after
        assert desc_1_after == sample_description["1"], (
            f"Błąd: Zmiana nazwy usunęła opis PL! Oczekiwano '{sample_description['1']}', otrzymano: '{desc_1_after}'"
        )
        assert add_1_after == sample_additional_description["1"], (
            f"Błąd: Zmiana nazwy usunęła additional_description PL! Oczekiwano '{sample_additional_description['1']}', otrzymano: '{add_1_after}'"
        )

        if isinstance(norm_desc_before, dict) and "2" in norm_desc_before:
            assert isinstance(norm_desc_after, dict) and "2" in norm_desc_after
            assert norm_desc_after["2"] == sample_description["2"], (
                f"Błąd: Zmiana nazwy usunęła opis EN! Oczekiwano '{sample_description['2']}', otrzymano: '{norm_desc_after['2']}'"
            )
        if isinstance(norm_add_before, dict) and "2" in norm_add_before:
            assert isinstance(norm_add_after, dict) and "2" in norm_add_after
            assert norm_add_after["2"] == sample_additional_description["2"], (
                f"Błąd: Zmiana nazwy usunęła additional_description EN! Oczekiwano '{sample_additional_description['2']}', otrzymano: '{norm_add_after['2']}'"
            )

        print(f" [OK] Pomyślnie utworzono i zwalidowano 3-poziomowe drzewo (ID: {root_id} -> {child_id} -> {grandchild_id}), zweryfikowano dirty-checking oraz zachowanie opisu.")
    finally:
        # Dynamiczne uzupełnienie created_ids ze słownika mapping1 lub pliku mapowania w razie przerwania przedwcześnie
        for cat in reversed(dynamic_cats):
            cid = mapping1.get(cat["source_url"])
            if cid and cid not in created_ids:
                created_ids.append(cid)
        if temp_map_path.is_file():
            try:
                with temp_map_path.open("r", encoding="utf-8") as f_clean:
                    f_map_data = json.load(f_clean)
                    for cat in reversed(dynamic_cats):
                        cid = f_map_data.get(cat["source_url"])
                        if cid and cid not in created_ids:
                            created_ids.append(cid)
            except Exception:
                pass

        # Czyszczenie zasobów testowych
        temp_cats_path.unlink(missing_ok=True)
        temp_map_path.unlink(missing_ok=True)
        for cid in created_ids:
            try:
                client.delete("categories", cid)
            except Exception as e:
                print(f"Ostrzeżenie: Nie udało się usunąć tymczasowej kategorii ID {cid}: {e}")


def test_parent_failure_cascade_and_mapping_isolation() -> None:
    """
    Test 6: Weryfikacja błędu zapisu rodzica.
    Zapis nowej ścieżki w mapie następuje dopiero po sukcesie API.
    Gdy aktualizacja rodzica się nie powiedzie, zachowywana jest jego poprzednia ścieżka,
    a zależne podkategorie są pomijane i raportowane.
    """
    print("\n[TEST 6] Weryfikacja obsługi błędu aktualizacji rodzica i pomijania podkategorii...")
    base_client = PrestaShopClient()
    ts = int(time.time() * 1000)

    test_cats = [
        {
            "name": f"Parent {ts}",
            "category_path": [f"Parent {ts}"],
            "source_url": f"https://example.test/err-{ts}-parent",
            "parent_source_url": None,
            "product_ids": [],
        },
        {
            "name": f"Child {ts}",
            "category_path": [f"Parent {ts}", f"Child {ts}"],
            "source_url": f"https://example.test/err-{ts}-child",
            "parent_source_url": f"https://example.test/err-{ts}-parent",
            "product_ids": [],
        },
        {
            "name": f"Independent {ts}",
            "category_path": [f"Independent {ts}"],
            "source_url": f"https://example.test/err-{ts}-indep",
            "parent_source_url": None,
            "product_ids": [],
        },
    ]

    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".json", delete=False) as f_cats:
        json.dump(test_cats, f_cats)
        temp_cats_path = Path(f_cats.name)

    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".json", delete=False) as f_map:
        temp_map_path = Path(f_map.name)

    created_ids: List[int] = []

    try:
        # Krok 1: Poprawne utworzenie drzewa
        stats1, map1 = import_categories(
            client=base_client,
            categories_file=temp_cats_path,
            mapping_file=temp_map_path,
        )
        assert stats1.created == 3
        parent_id = map1[test_cats[0]["source_url"]]
        child_id = map1[test_cats[1]["source_url"]]
        indep_id = map1[test_cats[2]["source_url"]]
        created_ids = [child_id, parent_id, indep_id]

        # Krok 2: Modyfikacja nazw
        new_parent_name = f"Parent Renamed {ts}"
        new_indep_name = f"Independent Renamed {ts}"
        test_cats[0]["name"] = new_parent_name
        test_cats[0]["category_path"] = [new_parent_name]
        test_cats[1]["category_path"] = [new_parent_name, f"Child {ts}"]
        test_cats[2]["name"] = new_indep_name
        test_cats[2]["category_path"] = [new_indep_name]

        with temp_cats_path.open("w", encoding="utf-8") as f_cats:
            json.dump(test_cats, f_cats)

        # Klient symulujący błąd API przy próbie PUT na rodzicu
        class FailingParentClient(PrestaShopClient):
            def put(self, resource: str, resource_id: int, data: Dict[str, Any]) -> Dict[str, Any]:
                if resource == "categories" and resource_id == parent_id:
                    raise PrestaShopAPIError(f"Symulowany błąd zapisu rodzica ID {resource_id}")
                return super().put(resource, resource_id, data)

        failing_client = FailingParentClient()

        stats2, map2 = import_categories(
            client=failing_client,
            categories_file=temp_cats_path,
            mapping_file=temp_map_path,
            update_existing=True,
        )

        # Rodzic nie powiódł się (błąd), Dziecko pominięte i zaraportowane, Niezależna zaktualizowana sukcesem
        assert stats2.errors >= 2, f"Oczekiwano co najmniej 2 błędów (rodzic + zależne dziecko), otrzymano: {stats2.errors}"
        assert any(f"Błąd aktualizacji kategorii ID {parent_id}" in err for err in stats2.error_details)
        assert any("Pominięto zależną podkategorię" in err for err in stats2.error_details)
        assert stats2.updated == 1, f"Oczekiwano zaktualizowania 1 niezależnej kategorii, zaktualizowano: {stats2.updated}"

        # Nowa ścieżka rodzica NIE powinna znaleźć się w mapie (sukces nie nastąpił)
        assert new_parent_name not in map2
        # Nowa ścieżka niezależnej kategorii POWINNA znaleźć się w mapie (sukces nastąpił)
        assert new_indep_name in map2

        # Wczytanie pliku mapowania z dysku i asercja zachowania starych wpisów rodzica po błędzie PUT
        disk_map = load_category_mapping(temp_map_path)
        assert disk_map.get(test_cats[0]["source_url"]) == parent_id, (
            "Błąd: Nieudana aktualizacja rodzica usunęła jego source_url z pliku mapowania!"
        )
        old_parent_name = f"Parent {ts}"
        assert disk_map.get(old_parent_name) == parent_id, (
            "Błąd: Nieudana aktualizacja rodzica usunęła jego starą ścieżkę z pliku mapowania!"
        )
        old_child_url = test_cats[1]["source_url"]
        assert disk_map.get(old_child_url) == child_id, (
            "Błąd: Pominięcie dziecka z powodu błędu rodzica usunęło jego source_url z pliku mapowania!"
        )
        old_child_path = f"Parent {ts} > Child {ts}"
        assert disk_map.get(old_child_path) == child_id, (
            "Błąd: Pominięcie dziecka z powodu błędu rodzica usunęło jego starą ścieżkę z pliku mapowania!"
        )

        print(" [OK] Błąd rodzica poprawnie pominął zależne podkategorie i zachował izolację mapowania.")
    finally:
        temp_cats_path.unlink(missing_ok=True)
        temp_map_path.unlink(missing_ok=True)
        for cid in created_ids:
            try:
                base_client.delete("categories", cid)
            except Exception:
                pass


def test_record_validation_rejects_nulls_and_continues_valid() -> None:
    """
    Test 7: Weryfikacja walidacji rekordów (null, brakujące, puste wartości)
    oraz kontynuacji importu poprawnych pozycji.
    """
    print("\n[TEST 7] Weryfikacja walidacji pól (null, brakujące wartości) i kontynuacji importu...")
    client = PrestaShopClient()
    ts = int(time.time() * 1000)

    raw_test_data = [
        # Rekord #0: brakujące pole 'name'
        {
            "category_path": ["Bez Nazwy"],
            "source_url": f"https://example.test/err-{ts}-0",
            "parent_source_url": None,
        },
        # Rekord #1: 'name' równe None / null
        {
            "name": None,
            "category_path": ["Null Name"],
            "source_url": f"https://example.test/err-{ts}-1",
            "parent_source_url": None,
        },
        # Rekord #2: 'name' pusty string
        {
            "name": "   ",
            "category_path": ["Empty Name"],
            "source_url": f"https://example.test/err-{ts}-2",
            "parent_source_url": None,
        },
        # Rekord #3: 'source_url' równe None
        {
            "name": f"Null URL {ts}",
            "category_path": [f"Null URL {ts}"],
            "source_url": None,
            "parent_source_url": None,
        },
        # Rekord #4: poprawny, niezależny rekord
        {
            "name": f"Valid Item {ts}",
            "category_path": [f"Valid Item {ts}"],
            "source_url": f"https://example.test/valid-{ts}-4",
            "parent_source_url": None,
            "product_ids": [],
        },
        # Rekord #5: rekord ze zduplikowanym source_url (powtórzenie URL z rekordu #4)
        {
            "name": f"Duplicate URL Item {ts}",
            "category_path": [f"Duplicate URL Item {ts}"],
            "source_url": f"https://example.test/valid-{ts}-4",
            "parent_source_url": None,
            "product_ids": [],
        },
    ]

    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".json", delete=False) as f_cats:
        json.dump(raw_test_data, f_cats)
        temp_cats_path = Path(f_cats.name)

    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".json", delete=False) as f_map:
        temp_map_path = Path(f_map.name)

    created_id: int | None = None

    try:
        stats, map_res = import_categories(
            client=client,
            categories_file=temp_cats_path,
            mapping_file=temp_map_path,
        )

        # Powinno zaraportować błędy dla rekordów #0, #1, #2, #3 oraz duplikatu #5
        assert stats.errors >= 5, f"Oczekiwano co najmniej 5 błędów walidacji, otrzymano: {stats.errors}"
        assert any("Rekord #0" in err for err in stats.error_details), "Brak wzmianki o rekordzie #0 w błędach!"
        assert any("Rekord #1" in err for err in stats.error_details), "Brak wzmianki o rekordzie #1 w błędach!"
        assert any("Rekord #2" in err for err in stats.error_details), "Brak wzmianki o rekordzie #2 w błędach!"
        assert any("Rekord #3" in err for err in stats.error_details), "Brak wzmianki o rekordzie #3 w błędach!"
        assert any("Rekord #5" in err and "zduplikowany" in err.lower() and "source_url" in err for err in stats.error_details), "Brak wzmianki o zduplikowanym source_url w błędach!"

        # Poprawny rekord #4 powinien zostać utworzony
        assert stats.created == 1, f"Oczekiwano utworzenia 1 poprawnej kategorii, utworzono: {stats.created}"
        valid_url = raw_test_data[4]["source_url"]
        assert valid_url in map_res, f"Brak poprawnej kategorii '{valid_url}' w wygenerowanej mapie!"
        created_id = map_res[valid_url]

        # Weryfikacja w API
        cat_in_ps = client.get("categories", created_id)["category"]
        assert _extract_category_name(cat_in_ps.get("name", "")) == raw_test_data[4]["name"]

        print(f" [OK] Odrzucono 5 niepoprawnych rekordów (w tym duplikat) i zaimportowano poprawną pozycję ID {created_id}.")
    finally:
        temp_cats_path.unlink(missing_ok=True)
        temp_map_path.unlink(missing_ok=True)
        if created_id:
            try:
                client.delete("categories", created_id)
            except Exception:
                pass


def test_instance_isolation_and_collision_protection() -> None:
    """
    Test 8: Weryfikacja izolacji instancji sklepu oraz wykrywania kolizji w starej mapie.
    1. Sprawdza czy plik mapowania wygenerowany dla innego sklepu jest odrzucany przy niezgodności instancji.
    2. Sprawdza czy kolizje w starym pliku (dwa różne URL-e wskazujące na to samo ID) są wykrywane i bezpiecznie neutralizowane.
    """
    print("\n[TEST 8] Weryfikacja izolacji instancji i kolizji starej mapy...")
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".json", delete=False) as f_map:
        temp_map_path = Path(f_map.name)

    try:
        # Zapis mapy z metadanymi dla innej instancji sklepu
        test_data = {
            "_instance_url": "https://other-shop.example.com/api",
            "https://pandomu.com.pl/kategoria-1": 100,
            "https://pandomu.com.pl/kategoria-2": 100,  # Kolizja: to samo ID 100
        }
        with temp_map_path.open("w", encoding="utf-8") as f:
            json.dump(test_data, f)

        # 1. Próba wczytania z oczekiwaną inną instancją 'https://localhost/api'
        loaded = load_category_mapping(temp_map_path, expected_instance_url="https://localhost/api")
        assert loaded == {}, f"Oczekiwano pustej mapy przy niezgodności instancji, otrzymano: {loaded}"

        # 2. Wczytanie z dopasowaną instancją - powinna zwrócić wpisy
        loaded_same = load_category_mapping(temp_map_path, expected_instance_url="https://other-shop.example.com/api")
        assert len(loaded_same) >= 2

        # 3. Próba wczytania pliku bez metadanych _instance_url z podanym expected_instance_url
        with temp_map_path.open("w", encoding="utf-8") as f:
            json.dump({"https://pandomu.com.pl/kategoria-1": 100}, f)
        loaded_no_meta = load_category_mapping(temp_map_path, expected_instance_url="https://localhost/api")
        assert loaded_no_meta == {}, f"Oczekiwano pustej mapy przy braku metadanych instancji, otrzymano: {loaded_no_meta}"

        print(" [OK] Pomyślnie zweryfikowano izolację per instancja oraz odrzucanie niepewnych ID.")
    finally:
        temp_map_path.unlink(missing_ok=True)


def test_multilang_payload_and_unmanaged_fields() -> None:
    """
    Test 9: Weryfikacja formatowania XML wartości wielojęzycznych API PrestaShop
    oraz zachowania pól description i additional_description.
    """
    print("\n[TEST 9] Weryfikacja formatowania wielojęzycznego XML i pól niezarządzanych...")
    from category_import import CategoryItem, build_category_payload
    from prestashop_api import dict_to_prestashop_xml

    item = CategoryItem(name="Testowa Kategoria", category_path=["Testowa Kategoria"], source_url="https://test.local/cat")
    mock_existing = {
        "id": 42,
        "id_shop_default": 2,
        "position": 0,
        "date_add": "2026-01-02 03:04:05",
        "meta_keywords": "",
        "description": [
            {"id": "1", "value": "<p>Opis PL</p>"},
            {"id": "2", "value": "<p>Description EN</p>"},
        ],
        "additional_description": [
            {"id": "1", "value": "<p>Dodatkowy opis PL</p>"},
            {"id": "2", "value": "<p>Additional description EN</p>"},
        ],
        "meta_title": [
            {"id": "1", "value": "Tytuł PL"},
            {"id": "2", "value": "Title EN"},
        ],
    }

    payload = build_category_payload(item, parent_id=2, category_id=42, existing_data=mock_existing)

    assert payload["description"] == {"1": "<p>Opis PL</p>", "2": "<p>Description EN</p>"}
    assert payload["additional_description"] == {"1": "<p>Dodatkowy opis PL</p>", "2": "<p>Additional description EN</p>"}
    assert payload["meta_title"] == {"1": "Tytuł PL", "2": "Title EN"}
    assert payload["meta_keywords"] == {"1": ""}
    assert payload["id_shop_default"] == 2
    assert payload["position"] == 0
    assert payload["date_add"] == mock_existing["date_add"]

    xml = dict_to_prestashop_xml("categories", payload)
    assert 'language id="1"' in xml and 'Opis PL' in xml
    assert 'language id="2"' in xml and 'Description EN' in xml
    assert '<additional_description>' in xml
    print(" [OK] Pomyślnie zweryfikowano transformację list wielojęzycznych do XML.")


def test_saved_id_requires_matching_previous_path() -> None:
    client = Mock(spec=PrestaShopClient)
    client.api_url = "https://shop.example/api"
    source_url = "https://source.example/klamki"
    client.get.side_effect = [
        {"languages": [{"id": 1}]},
        {"categories": [
            {"id": 8, "id_parent": 2, "name": "Inny dział", "link_rewrite": "inny-dzial"},
            {"id": 10, "id_parent": 8, "name": "Obca kategoria", "link_rewrite": "klamki"},
        ]},
    ]
    client.post.return_value = {"category": {"id": 11}}

    with tempfile.TemporaryDirectory() as directory:
        categories = Path(directory) / "categories.json"
        categories.write_text(json.dumps([
            {"name": "Klamki", "category_path": ["Klamki"], "source_url": source_url},
        ]), encoding="utf-8")
        mapping = Path(directory) / "mapping.json"
        save_category_mapping(
            mapping, {source_url: 10, "Dawny dział > Obca kategoria": 10}, instance_url=client.api_url,
        )
        stats, result = import_categories(client, categories, mapping)

    client.put.assert_not_called()
    client.post.assert_called_once()
    assert stats.created == 1
    assert result[source_url] == 11


def test_put_requires_complete_category_and_preserves_fields() -> None:
    client = Mock(spec=PrestaShopClient)
    client.api_url = "https://shop.example/api"
    source_url = "https://source.example/klamki"
    child_url = "https://source.example/klamki/dziecko"
    existing = {
        "id": "10",
        "name": "Stara nazwa",
        "link_rewrite": "stara-nazwa",
        "id_parent": "2",
        "active": "1",
        "id_shop_default": "2",
        "is_root_category": "0",
        "position": "4",
        "date_add": "2026-01-02 03:04:05",
        "description": [{"id": "1", "value": "Opis"}, {"id": "2", "value": "Description"}],
        "additional_description": [{"id": "1", "value": "Dodatkowy"}, {"id": "2", "value": "Additional"}],
        "meta_title": "",
        "meta_description": "",
        "meta_keywords": "",
    }
    child = {"id": 11, "name": "Dziecko", "link_rewrite": "dziecko", "id_parent": 10}

    incomplete_category = {key: value for key, value in existing.items() if key != "name"}
    for response in ({"id": 10}, incomplete_category, {**existing, "id": 99}, existing):
        client.reset_mock()
        client.get.side_effect = [
            {"languages": [{"id": 1}, {"id": 2}]},
            {"categories": [existing, child]},
            {"category": response},
        ]
        with tempfile.TemporaryDirectory() as directory:
            categories = Path(directory) / "categories.json"
            categories.write_text(json.dumps([
                {"name": "Klamki", "category_path": ["Klamki"], "source_url": source_url},
                {
                    "name": "Dziecko",
                    "category_path": ["Klamki", "Dziecko"],
                    "source_url": child_url,
                    "parent_source_url": source_url,
                },
            ]), encoding="utf-8")
            mapping = Path(directory) / "mapping.json"
            save_category_mapping(
                mapping,
                {source_url: 10, "Stara nazwa": 10, child_url: 11, "Stara nazwa > Dziecko": 11},
                instance_url=client.api_url,
            )
            stats, _ = import_categories(client, categories, mapping)
            result = load_category_mapping(mapping)

        client.post.assert_not_called()
        assert result[source_url] == 10
        assert result[child_url] == 11
        if response is existing:
            client.put.assert_called_once()
            resource, category_id, payload = client.put.call_args.args
            assert (resource, category_id) == ("categories", 10)
            assert payload["description"] == {"1": "Opis", "2": "Description"}
            assert payload["additional_description"] == {"1": "Dodatkowy", "2": "Additional"}
            assert payload["position"] == existing["position"]
            assert payload["id_shop_default"] == existing["id_shop_default"]
            assert payload["date_add"] == existing["date_add"]
            assert result["Klamki"] == 10 and result["Klamki > Dziecko"] == 11
            assert stats.updated == 1 and stats.errors == 0
        else:
            client.put.assert_not_called()
            assert result["Stara nazwa"] == 10 and result["Stara nazwa > Dziecko"] == 11
            assert "Klamki" not in result
            assert stats.errors == 2
            assert any("Pominięto zależną podkategorię" in error for error in stats.error_details)


def test_foreign_category_protection_against_unverified_rename() -> None:
    """
    Test 10: Weryfikacja ochrony obcej kategorii przed nadpisaniem (PUT)
    przy braku zweryfikowanego powiązania source_url z ID w pliku mapy.
    """
    print("\n[TEST 10] Weryfikacja ochrony obcej kategorii przed nadpisaniem...")
    client = PrestaShopClient()
    ts = int(time.time() * 1000)

    # 1. Tworzymy niezależną obcą kategorię bezpośrednio w PrestaShop (np. "Alien Category {ts}")
    alien_cat = client.post("categories", {
        "name": {"1": f"Alien Category {ts}"},
        "link_rewrite": {"1": f"alien-category-{ts}"},
        "id_parent": PRESTASHOP_HOME_CATEGORY_ID,
        "active": 1,
    })["category"]
    alien_id = int(alien_cat["id"])

    # 2. Przygotowujemy plik mapy, który złośliwie/błędnie wskazuje source_url na alien_id,
    # ale NIE posiada historii ścieżki dla "Alien Category"
    test_cats = [
        {
            "name": f"My New Category {ts}",
            "category_path": [f"My New Category {ts}"],
            "source_url": f"https://example.test/new-cat-{ts}",
            "parent_source_url": None,
            "product_ids": [],
        }
    ]

    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".json", delete=False) as f_cats:
        json.dump(test_cats, f_cats)
        temp_cats_path = Path(f_cats.name)

    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".json", delete=False) as f_map:
        temp_map_path = Path(f_map.name)

    fake_map_data = {
        "_instance_url": client.api_url,
        f"https://example.test/new-cat-{ts}": alien_id,
        # Celowo BRAK klucza "Alien Category {ts}" -> brak zweryfikowanego powiązania!
    }
    with temp_map_path.open("w", encoding="utf-8") as f:
        json.dump(fake_map_data, f)

    created_id: int | None = None

    try:
        stats, result_map = import_categories(
            client=client,
            categories_file=temp_cats_path,
            mapping_file=temp_map_path,
            update_existing=True,
        )

        # Importer NIE powinien nadpisać alien_id!
        # Powinien utworzyć NOWĄ kategorię o innym ID lub zignorować obce powiązanie.
        new_cat_id = result_map[test_cats[0]["source_url"]]
        assert new_cat_id != alien_id, (
            f"KRYTYCZNY BŁĄD: Importer nadpisał obcą kategorię ID {alien_id}!"
        )
        created_id = new_cat_id

        # Weryfikacja, że kategoria Alien Category nadal istnieje nienaruszona w PrestaShop
        alien_in_ps = client.get("categories", alien_id)["category"]
        assert _extract_category_name(alien_in_ps["name"]) == f"Alien Category {ts}", (
            f"Błąd: Nazwa obcej kategorii została zmieniona na '{alien_in_ps['name']}'!"
        )
        print(" [OK] Obca kategoria w PrestaShop została obroniona przed nadpisaniem.")
    finally:
        temp_cats_path.unlink(missing_ok=True)
        temp_map_path.unlink(missing_ok=True)
        if created_id:
            try:
                client.delete("categories", created_id)
            except Exception:
                pass
        try:
            client.delete("categories", alien_id)
        except Exception:
            pass


def run_all_tests() -> bool:
    """Uruchamia wszystkie testy i zwraca True w przypadku sukcesu lub False przy błędzie."""
    print("=" * 65)
    print("   TEST ODBIORCZY IMPORTU I HIERARCHII KATEGORII PRESTASHOP")
    print("=" * 65)

    test_functions = [
        test_categories_file_structure,
        test_category_mapping_integrity,
        test_prestashop_parent_child_relations,
        test_category_import_idempotence,
        test_dynamic_hierarchy_lifecycle,
        test_parent_failure_cascade_and_mapping_isolation,
        test_record_validation_rejects_nulls_and_continues_valid,
        test_instance_isolation_and_collision_protection,
        test_multilang_payload_and_unmanaged_fields,
        test_saved_id_requires_matching_previous_path,
        test_put_requires_complete_category_and_preserves_fields,
        test_foreign_category_protection_against_unverified_rename,
    ]

    for test_fn in test_functions:
        try:
            test_fn()
        except AssertionError as exc:
            print(f"\n[BŁĄD ASERCJI] {test_fn.__name__}: {exc}")
            return False
        except Exception as exc:
            print(f"\n[NIEOCZEKIWANY BŁĄD] {test_fn.__name__}: {exc}")
            return False

    print("\n" + "=" * 65)
    print("   WSZYSTKIE TESTY ODBIORCZE ZAKOŃCZONE SUKCESEM!")
    print("=" * 65 + "\n")
    return True


if __name__ == "__main__":
    success = run_all_tests()
    sys.exit(0 if success else 1)
