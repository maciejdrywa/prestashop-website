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
from pathlib import Path
import sys
import tempfile
import time
from typing import Any, Dict, List, Set, Tuple

# Zapewnienie poprawnego importu modułów
_CURRENT_DIR = Path(__file__).resolve().parent
_PROJECT_ROOT = _CURRENT_DIR.parent
if str(_CURRENT_DIR) not in sys.path:
    sys.path.insert(0, str(_CURRENT_DIR))
if str(_PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(_PROJECT_ROOT))

from prestashop_api import (
    PrestaShopClient,
    PrestaShopAPIError,
)
from import_categories import (
    import_categories,
    load_and_validate_categories,
    load_category_mapping,
    _extract_category_name,
    PRESTASHOP_HOME_CATEGORY_ID,
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

    # Sprawdzenie czy wszystkie kategorie ze scraper-results/categories.json mają odwzorowanie
    items = load_and_validate_categories(CATEGORIES_FILE)
    for item in items:
        # Mapowanie po source_url
        assert item.source_url in mapping, f"Brak wpisu dla source_url '{item.source_url}' w pliku mapowania!"
        cat_id_by_url = mapping[item.source_url]
        assert isinstance(cat_id_by_url, int) and cat_id_by_url > 0, (
            f"Nieprawidłowy ID kategorii dla URL '{item.source_url}': {cat_id_by_url}"
        )

        # Mapowanie po pełnej ścieżce tekstowej
        path_str = item.formatted_path
        assert path_str in mapping, f"Brak wpisu dla ścieżki '{path_str}' w pliku mapowania!"
        cat_id_by_path = mapping[path_str]
        assert cat_id_by_path == cat_id_by_url, (
            f"Niezgodność ID dla URL ({cat_id_by_url}) i ścieżki ({cat_id_by_path}) dla kategorii '{item.name}'"
        )

    print(f" [OK] Słownik mapowania zawiera poprawne ID dla wszystkich {len(items)} kategorii.")


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

        # Weryfikacja w API zaktualizowanej nazwy
        updated_grandchild_res = client.get("categories", grandchild_id)
        updated_data = (
            updated_grandchild_res["category"]
            if isinstance(updated_grandchild_res, dict) and "category" in updated_grandchild_res
            else updated_grandchild_res
        )
        assert _extract_category_name(updated_data.get("name", "")) == updated_grandchild_name

        print(f" [OK] Pomyślnie utworzono i zwalidowano 3-poziomowe drzewo (ID: {root_id} -> {child_id} -> {grandchild_id}) oraz zweryfikowano dirty-checking.")
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
