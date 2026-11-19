"""Testy offline formatu kategorii, mapowania i zachowania pól przy aktualizacji.

Testy działającego API są w test_api_integration.py; awarie zapisu i konflikty
w test_category_recovery.py. Żaden test tego modułu nie zapisuje map w repozytorium.
"""

from __future__ import annotations

import json
import sys
import tempfile
from pathlib import Path
from typing import Set, Tuple
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
    import_categories,
    load_and_validate_categories,
    load_category_mapping,
    save_category_mapping,
)
from prestashop_api import (  # noqa: E402
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


def test_category_mapping_integrity(tmp_path) -> None:
    """Mapa offline powstaje z fixture, bez zależności od historycznych ID sklepu."""
    path = tmp_path / "mapping.json"
    items = load_and_validate_categories(CATEGORIES_FILE)
    mapping = {}
    for index, item in enumerate(items, 10):
        mapping[item.source_url] = index
        mapping[item.formatted_path] = index
    save_category_mapping(path, mapping, instance_url="https://test.example/api", identity="fixture")
    loaded = load_category_mapping(path, expected_instance_url="https://test.example/api", expected_identity="fixture")
    assert len({loaded[item.source_url] for item in items}) == len(items)
    assert all(loaded[item.source_url] == loaded[item.formatted_path] for item in items)






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
    client.get_instance_identity.return_value = "test-installation"
    client.api_url = "https://shop.example/api"
    source_url = "https://source.example/klamki"
    client.get.side_effect = [
        {"languages": [{"id": 1}]},
        {"categories": [
            {"id": 8, "id_parent": 2, "name": "Inny dział", "link_rewrite": "inny-dzial", "active": 1},
            {"id": 10, "id_parent": 8, "name": "Obca kategoria", "link_rewrite": "klamki", "active": 1},
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
            mapping, {source_url: 10, "Dawny dział > Obca kategoria": 10}, instance_url=client.api_url, identity="test-installation",
        )
        stats, result = import_categories(client, categories, mapping)

    client.put.assert_not_called()
    client.post.assert_called_once()
    assert stats.created == 1
    assert result[source_url] == 11


def test_put_requires_complete_category_and_preserves_fields() -> None:
    client = Mock(spec=PrestaShopClient)
    client.get_instance_identity.return_value = "test-installation"
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
    child = {"id": 11, "name": "Dziecko", "link_rewrite": "dziecko", "id_parent": 10, "active": 1}

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
                instance_url=client.api_url, identity="test-installation",
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



if __name__ == "__main__":
    import pytest
    raise SystemExit(pytest.main([__file__, "-q"]))
