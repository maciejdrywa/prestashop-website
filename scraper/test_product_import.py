"""
Zestaw testów jednostkowych i integracyjnych dla modułu importu produktów (D2.3).

Weryfikuje:
1. Precyzję obliczeń finansowych Decimal(6), zaokrąglenia brutto do groszy i mapowanie stawek VAT.
2. Reguły magazynowe: zakres 0-10 sztuk, twardy wymóg 5-20% stanów zerowych, blokadę zamawiania out_of_stock=0.
3. Walidację rekordów products.json, limity znaków i deterministyczne dobieranie wagi (fallback kategorii).
4. Rozwiązywanie kategorii nadrzędnych i domyślnych z category_mapping.json.
5. Atomowy zapis mapowania produktów z maską uprawnień 0664.
6. Mechanizm dirty-checkingu (pomijanie zbędnych żądań PUT dla produktów, kombinacji i stanów).
7. Odporność na błędy per rekord i pełną idempotencję importu.
"""

from __future__ import annotations

import json
import sys
import tempfile
from decimal import Decimal
from pathlib import Path
from typing import List
from unittest.mock import MagicMock

import pytest

try:
    from prestashop_api import dict_to_prestashop_xml
except ImportError:
    from scraper.prestashop_api import dict_to_prestashop_xml

from product_import import (
    ProductItem,
    VariantItem,
    build_combination_payload,
    build_product_payload,
    calculate_gross,
    calculate_netto,
    calculate_price_modifier,
    distribute_stock_to_variants,
    ensure_product_feature,
    ensure_product_feature_value,
    format_decimal,
    generate_deterministic_stock_plan,
    get_tax_rules_group_id,
    import_products,
    is_combination_dirty,
    is_product_dirty,
    load_and_validate_products,
    load_product_mapping,
    resolve_product_categories,
    resolve_product_weight,
    round_gross,
    round_netto,
    save_product_mapping,
    update_stock_available,
    validate_product_record,
    validate_stock_quantity,
    verify_stock_ratio,
)

_PROJECT_ROOT = Path(__file__).resolve().parent.parent


# ==============================================================================
# 1. TESTY KALKULACJI CEN, PODATKÓW I PRECYZJI DECIMAL
# ==============================================================================

def test_decimal_precision_and_rounding():
    """Weryfikuje precyzję 6 miejsc dla netto i 2 miejsc dla brutto."""
    netto = round_netto("48.7723578")
    assert netto == Decimal("48.772358")
    assert format_decimal(netto, 6) == "48.772358"

    gross = round_gross("59.994")
    assert gross == Decimal("59.99")
    gross_half_up = round_gross("59.995")
    assert gross_half_up == Decimal("60.00")


def test_vat_rate_to_tax_rules_group_mapping():
    """Weryfikuje mapowanie stawek VAT na id_tax_rules_group w PrestaShop."""
    assert get_tax_rules_group_id(23.0) == 1
    assert get_tax_rules_group_id(Decimal("23")) == 1
    assert get_tax_rules_group_id("8.0") == 2
    assert get_tax_rules_group_id(Decimal("8")) == 2
    assert get_tax_rules_group_id(5.0) == 3
    assert get_tax_rules_group_id(0.0) == 4

    with pytest.raises(ValueError, match="Nieobsługiwana stawka VAT"):
        get_tax_rules_group_id(19.0)


def test_gross_and_netto_calculations():
    """Weryfikuje matematyczną zgodność przeliczania netto <-> brutto dla 23% VAT."""
    netto = Decimal("48.772358")
    gross = calculate_gross(netto, 23.0)
    assert gross == Decimal("59.99")

    # Przeliczenie z brutto na netto
    computed_netto = calculate_netto(gross, 23.0)
    assert computed_netto == Decimal("48.772358")


def test_combination_price_modifier():
    """Weryfikuje wyliczanie modyfikatora ceny netto wariantu względem ceny bazowej."""
    base_net = Decimal("100.000000")
    variant_higher = Decimal("110.500000")
    variant_lower = Decimal("95.250000")
    variant_equal = Decimal("100.000000")

    assert calculate_price_modifier(variant_higher, base_net) == Decimal("10.500000")
    assert calculate_price_modifier(variant_lower, base_net) == Decimal("-4.750000")
    assert calculate_price_modifier(variant_equal, base_net) == Decimal("0.000000")


# ==============================================================================
# 2. TESTY ZARZĄDZANIA STANAMI MAGAZYNOWYMI (STOCK)
# ==============================================================================

def test_stock_quantity_validation():
    """Weryfikuje dopuszczalny zakres stanów magazynowych 0-10 sztuk."""
    assert validate_stock_quantity(0) == 0
    assert validate_stock_quantity(5) == 5
    assert validate_stock_quantity(10) == 10

    with pytest.raises(ValueError, match="poza dozwolonym zakresem"):
        validate_stock_quantity(-1)

    with pytest.raises(ValueError, match="poza dozwolonym zakresem"):
        validate_stock_quantity(11)

    with pytest.raises(ValueError, match="Brak wartości"):
        validate_stock_quantity(None)


def test_stock_zero_ratio_enforcement():
    """Weryfikuje twardy wymóg biznesowy: 5% do 20% produktów musi mieć stan = 0."""
    # 10 elementów: 1 zero = 10% (spełnia)
    stocks_valid = [0, 5, 3, 8, 2, 6, 1, 9, 4, 7]
    valid, pct = verify_stock_ratio(stocks_valid)
    assert valid is True
    assert pct == 10.0

    # 100 elementów: 3 zera = 3% (poniżej 5% -> nie spełnia)
    stocks_too_few_zeros = [0] * 3 + [5] * 97
    valid, pct = verify_stock_ratio(stocks_too_few_zeros)
    assert valid is False
    assert pct == 3.0

    # 100 elementów: 25 zer = 25% (powyżej 20% -> nie spełnia)
    stocks_too_many_zeros = [0] * 25 + [5] * 75
    valid, pct = verify_stock_ratio(stocks_too_many_zeros)
    assert valid is False
    assert pct == 25.0


def test_deterministic_stock_generation():
    """Weryfikuje deterministyczny plan stanów magazynowych i jego powtarzalność."""
    pids = [f"prod_{i}" for i in range(1, 101)]
    plan1 = generate_deterministic_stock_plan(pids)
    plan2 = generate_deterministic_stock_plan(pids)

    # 100% powtarzalność
    assert plan1 == plan2
    assert len(plan1) == 100

    # Wszystkie wartości w przedziale [0, 10]
    for qty in plan1.values():
        assert 0 <= qty <= 10

    # Odsetek zer w granicach 5% - 20%
    zero_count = sum(1 for q in plan1.values() if q == 0)
    assert 5 <= zero_count <= 20


def test_stock_distribution_to_variants():
    """Weryfikuje sprawiedliwy podział sumarycznego stanu magazynowego na warianty."""
    variant_ids = ["10", "20", "30"]
    # Total 7: warianty dostają 3, 2, 2 (suma = 7)
    dist = distribute_stock_to_variants(7, variant_ids)
    assert sum(dist.values()) == 7
    assert dist == {"10": 3, "20": 2, "30": 2}

    # Total 0: każdy wariant ma 0
    dist_zero = distribute_stock_to_variants(0, variant_ids)
    assert sum(dist_zero.values()) == 0
    assert all(q == 0 for q in dist_zero.values())


def test_stock_plan_determinism_under_limit():
    """Weryfikuje, że plan stanów magazynowych jest deterministyczny i niezależny od limitu."""
    pids = [f"prod_{i}" for i in range(1, 51)]
    plan_full = generate_deterministic_stock_plan(pids)

    # Dla dowolnego podzbioru (np. pierwszych 5 ID) stany w plan_full są identyczne
    subset_5 = pids[:5]
    for pid in subset_5:
        assert pid in plan_full
        assert 0 <= plan_full[pid] <= 10

    # Ponowne wygenerowanie planu dla całego zbioru daje dokładnie te same wartości
    plan_full_again = generate_deterministic_stock_plan(pids)
    for pid in subset_5:
        assert plan_full[pid] == plan_full_again[pid]


# ==============================================================================
# 3. TESTY WALIDACJI REKORDÓW PRODUKTÓW I REGUL WAGI
# ==============================================================================

def test_product_validation_success():
    """Weryfikuje pomyślną walidację poprawnego rekordu produktu."""
    raw = {
        "product_id": "100",
        "name": "Klamka do drzwi chrom",
        "description": "<p>Pełny opis HTML</p>",
        "description_short": "Krótki opis",
        "price_netto": 80.0,
        "vat_rate": 23.0,
        "weight": 0.75,
        "stock": 4,
        "category_paths": [["Okucia drzwiowe", "Klamki do drzwi"]],
        "category_source_urls": ["https://example.com/cat"],
        "breadcrumbs": ["Strona główna", "Okucia drzwiowe", "Klamki do drzwi", "Klamka chrom"],
    }
    item = validate_product_record(raw)
    assert item.product_id == "100"
    assert item.reference == "pandomu-100"
    assert item.price_netto == Decimal("80.000000")
    assert item.price_brutto == Decimal("98.40")
    assert item.weight == Decimal("0.750000")
    assert item.weight_origin == "source"
    assert item.stock == 4


def test_product_validation_rejects_invalid_fields():
    """Weryfikuje odrzucenie błędnych danych wejściowych."""
    # Brak product_id
    with pytest.raises(ValueError, match="product_id"):
        validate_product_record({"name": "Test"})

    # Ujemna cena netto
    with pytest.raises(ValueError, match="ujemną cenę"):
        validate_product_record({
            "product_id": "1",
            "name": "Test",
            "price_netto": -10.0,
            "category_paths": [["Kategoria"]],
        })


def test_product_validation_truncates_overlong_name():
    """Weryfikuje bezpieczne przycięcie zbyt długiej nazwy produktu (> 128 znaków)."""
    raw = {
        "product_id": "1",
        "name": "A" * 150,
        "price_netto": 10.0,
        "category_paths": [["Kategoria"]],
    }
    item = validate_product_record(raw)
    assert len(item.name) == 128
    assert item.name == "A" * 128


def test_product_validation_netto_fallback_from_brutto():
    """Weryfikuje obliczenie ceny netto z brutto, gdy brak price_netto w rekordzie."""
    raw = {
        "product_id": "2",
        "name": "Produkt z brutto",
        "price_brutto": 123.0,
        "vat_rate": 23.0,
        "category_paths": [["Kategoria"]],
    }
    item = validate_product_record(raw)
    assert item.price_netto == Decimal("100.000000")
    assert item.price_brutto == Decimal("123.00")

    # Gdy brak netto i brutto -> błąd
    with pytest.raises(ValueError, match="nie posiada ceny netto ani brutto"):
        validate_product_record({
            "product_id": "3",
            "name": "Brak cen",
            "category_paths": [["Kategoria"]],
        })


def test_category_weight_fallback_rules():
    """Weryfikuje dobór deterministycznej masy demonstracyjnej w przypadku braku wagi źródłowej."""
    # Kategoria: klamki do drzwi -> waga demo 0.5 kg
    paths = [["Okucia drzwiowe", "Klamki do drzwi"]]
    weight, origin = resolve_product_weight(None, paths)
    assert weight == Decimal("0.500000")
    assert origin == "demo"

    # Kategoria: zamki drzwiowe -> waga demo 1.0 kg
    paths2 = [["Okucia drzwiowe", "zamki drzwiowe"]]
    weight2, origin2 = resolve_product_weight(None, paths2)
    assert weight2 == Decimal("1.000000")
    assert origin2 == "demo"

    # Waga źródłowa ma pierwszeństwo przed regułą kategorii
    weight3, origin3 = resolve_product_weight(0.85, paths)
    assert weight3 == Decimal("0.850000")
    assert origin3 == "source"


# ==============================================================================
# 4. TESTY ROZWIĄZYWANIA KATEGORII I MAPOWANIA
# ==============================================================================

def test_resolve_product_categories():
    """Weryfikuje wyznaczanie kategorii domyślnej i pełnej ścieżki przodków."""
    category_mapping = {
        "Okucia drzwiowe": 11,
        "zamki drzwiowe": 23,
        "Okucia drzwiowe > zamki drzwiowe": 23,
        "Okucia drzwiowe > zamki drzwiowe > zamki nawierzchniowe": 64,
        "zamki nawierzchniowe": 64,
    }
    category_paths = [["Okucia drzwiowe", "zamki drzwiowe", "zamki nawierzchniowe"]]
    breadcrumbs = ["Strona główna", "Okucia drzwiowe", "zamki drzwiowe", "zamki nawierzchniowe", "Produkt X"]

    default_id, all_ids = resolve_product_categories(category_paths, breadcrumbs, category_mapping)
    assert default_id == 64
    assert all_ids == [11, 23, 64]


def test_product_mapping_atomic_persistence():
    """Weryfikuje atomowy zapis i uprawnienia 0664 dla product_mapping.json."""
    with tempfile.TemporaryDirectory() as tmp_dir:
        mapping_file = Path(tmp_dir) / "product_mapping.json"
        data = {"5": 20, "pandomu-5": 20, "12": 21, "pandomu-12": 21}

        save_product_mapping(mapping_file, data)
        assert mapping_file.is_file()

        # Sprawdzenie uprawnień: 0666 na Windowsie, 0664 na POSIX
        mode = oct(mapping_file.stat().st_mode & 0o777)
        if sys.platform == "win32":
            assert mode == oct(0o666)
        else:
            assert mode == oct(0o664)

        # Odczyt
        loaded = load_product_mapping(mapping_file)
        assert loaded == {"5": 20, "pandomu-5": 20, "12": 21, "pandomu-12": 21}


# ==============================================================================
# 5. TESTY DIRTY-CHECKINGU I INTEGRACJI API PRESTASHOP
# ==============================================================================

def test_product_dirty_checking():
    """Weryfikuje wykrywanie zmian w polach produktu (is_product_dirty)."""
    item = ProductItem(
        product_id="5",
        name="Zamek GAMA",
        description="Opis",
        description_short="Krótki",
        price_netto=Decimal("48.772358"),
        price_brutto=Decimal("59.99"),
        vat_rate=Decimal("23"),
        weight=Decimal("0.990000"),
    )

    existing_matching = {
        "id": 20,
        "reference": "pandomu-5",
        "name": "Zamek GAMA",
        "description": "Opis",
        "description_short": "Krótki",
        "price": "48.772358",
        "id_tax_rules_group": "1",
        "id_category_default": "64",
        "weight": "0.990000",
        "active": "1",
    }
    # Dane zgodne -> nie jest dirty
    assert is_product_dirty(existing_matching, item, default_category_id=64) is False

    # Zmiana opisu -> jest dirty
    existing_diff_desc = dict(existing_matching, description="Stary opis")
    assert is_product_dirty(existing_diff_desc, item, default_category_id=64) is True

    # Zmiana krótkiego opisu -> jest dirty
    existing_diff_short = dict(existing_matching, description_short="Stary krótki")
    assert is_product_dirty(existing_diff_short, item, default_category_id=64) is True

    # Zmiana ceny -> jest dirty
    existing_diff_price = dict(existing_matching, price="50.000000")
    assert is_product_dirty(existing_diff_price, item, default_category_id=64) is True

    # Zmiana kategorii domyślnej -> jest dirty
    assert is_product_dirty(existing_matching, item, default_category_id=65) is True

    # Nieaktywny w PrestaShop -> jest dirty
    existing_inactive = dict(existing_matching, active="0")
    assert is_product_dirty(existing_inactive, item, default_category_id=64) is True


def test_combination_dirty_checking():
    """Weryfikuje dirty-checking dla wariantów/kombinacji."""
    variant = VariantItem(
        variant_id="3",
        name="Wariant brąz",
        price_modifier=Decimal("5.000000"),
    )

    existing_matching = {
        "id": 40,
        "reference": "pandomu-5-combination-3",
        "price": "5.000000",
        "default_on": "1",
    }
    # Zgodne
    assert is_combination_dirty(existing_matching, variant, is_default=True) is False

    # Zmiana modyfikatora ceny
    existing_diff_price = dict(existing_matching, price="0.000000")
    assert is_combination_dirty(existing_diff_price, variant, is_default=True) is True


def test_stock_available_dirty_checking():
    """Weryfikuje, że niezmieniony stan magazynowy i out_of_stock=0 pomijają żądanie PUT."""
    mock_client = MagicMock()

    # Stan i out_of_stock są zgodne -> pomija PUT
    updated = update_stock_available(
        client=mock_client,
        stock_record_id=70,
        product_id=20,
        product_attribute_id=0,
        target_quantity=6,
        current_quantity=6,
        current_out_of_stock=0,
    )
    assert updated is False
    mock_client.put.assert_not_called()

    # Różna ilość -> wysyła PUT
    updated2 = update_stock_available(
        client=mock_client,
        stock_record_id=70,
        product_id=20,
        product_attribute_id=0,
        target_quantity=8,
        current_quantity=6,
        current_out_of_stock=0,
    )
    assert updated2 is True
    mock_client.put.assert_called_once()
    args, kwargs = mock_client.put.call_args
    assert args[0] == "stock_availables"
    assert args[1] == 70
    assert args[2]["quantity"] == "8"
    assert args[2]["out_of_stock"] == "0"
    assert args[2]["depends_on_stock"] == "0"

    # Ta sama ilość, ale depends_on_stock=1 -> wymusza PUT
    mock_client.reset_mock()
    updated3 = update_stock_available(
        client=mock_client,
        stock_record_id=70,
        product_id=20,
        product_attribute_id=0,
        target_quantity=6,
        current_quantity=6,
        current_out_of_stock=0,
        current_depends_on_stock=1,
    )
    assert updated3 is True
    mock_client.put.assert_called_once()


# ==============================================================================
# 6. TEST INTEGRALNOŚCI ŹRÓDŁA products.json I category_mapping.json
# ==============================================================================

def test_source_products_file_integrity():
    """Sprawdza spójność 574 produktów z products.json i relacji z category_mapping.json."""
    products_path = _PROJECT_ROOT / "scraper-results" / "products.json"
    mapping_path = _PROJECT_ROOT / "scraper-results" / "category_mapping.json"

    assert products_path.is_file(), "Brak pliku products.json!"
    assert mapping_path.is_file(), "Brak pliku category_mapping.json!"

    with products_path.open("r", encoding="utf-8") as f:
        products = json.load(f)

    with mapping_path.open("r", encoding="utf-8") as f:
        cat_map = json.load(f)

    assert len(products) == 574
    unique_ids = set(str(p["product_id"]) for p in products)
    assert len(unique_ids) == 574

    # Sprawdzenie, że wszystkie ścieżki produktów rozwiązują się w mapowaniu
    for p in products:
        default_id, all_ids = resolve_product_categories(
            p["category_paths"],
            p.get("breadcrumbs", []),
            cat_map,
        )
        assert isinstance(default_id, int) and default_id > 0
        assert len(all_ids) > 0

    # Sprawdzenie odsetka stanów zerowych w katalogu (5% - 20%)
    stocks = [p["stock"] for p in products if p.get("stock") is not None]
    for p in products:
        for v in p.get("variants", []):
            if v.get("stock") is not None:
                stocks.append(v["stock"])

    valid_ratio, pct = verify_stock_ratio(stocks)
    assert valid_ratio is True, f"Niepoprawny odsetek zer: {pct:.2f}%"
    assert 5.0 <= pct <= 20.0


# ==============================================================================
# 7. TESTY OCHRONY POWIĄZAŃ (PUT), CECH I TWARDYCH LIMITÓW MAGAZYNU
# ==============================================================================

def test_variant_stock_sum_never_exceeds_total_and_limit_ten():
    """Weryfikuje, że suma stanów wariantów po podziale nigdy nie przekracza totalu ani 10 sztuk."""
    for total in range(0, 11):
        for num_variants in [1, 2, 5, 8, 15]:
            variant_ids = [str(i) for i in range(1, num_variants + 1)]
            dist = distribute_stock_to_variants(total, variant_ids)
            assert sum(dist.values()) == total
            assert all(0 <= v <= 10 for v in dist.values())
            assert sum(dist.values()) <= 10


def test_build_product_payload_preserves_associations_on_put():
    """Weryfikuje, że operacja PUT zachowuje asocjacje images, combinations, product_option_values i product_features."""
    item = ProductItem(
        product_id="5",
        name="Zamek GAMA",
        description="Opis",
        description_short="Krótki",
        price_netto=Decimal("48.772358"),
        price_brutto=Decimal("59.99"),
        vat_rate=Decimal("23"),
        weight=Decimal("0.990000"),
    )

    existing_full = {
        "id": 20,
        "associations": {
            "images": [{"id": "24"}, {"id": "25"}],
            "combinations": [{"id": "40"}, {"id": "41"}],
            "product_option_values": [{"id": "26"}, {"id": "27"}],
            "product_features": [{"id": "1", "id_feature_value": "2"}],
        },
        "id_shop_default": "1",
    }

    payload = build_product_payload(
        product=item,
        default_category_id=64,
        associated_category_ids=[11, 23, 64],
        active_languages=["1"],
        product_id=20,
        existing_full_product=existing_full,
    )

    assoc = payload["associations"]
    assert "categories" in assoc
    assert assoc["categories"] == [{"id": "11"}, {"id": "23"}, {"id": "64"}]
    assert assoc["images"] == [{"id": "24"}, {"id": "25"}]
    assert assoc["combinations"] == [{"id": "40"}, {"id": "41"}]
    assert assoc["product_option_values"] == [{"id": "26"}, {"id": "27"}]
    assert assoc["product_features"] == [{"id": "1", "id_feature_value": "2"}]


def test_product_features_sync_and_payload():
    """Weryfikuje tworzenie/keszowanie cech i dołączanie ich do payloadu produktu."""
    mock_client = MagicMock()
    features_cache = {"kolor": 10}
    values_cache = {(10, "czerwony"): 101}

    # Istniejąca cecha
    fid = ensure_product_feature(mock_client, "Kolor", features_cache, ["1"])
    assert fid == 10
    mock_client.post.assert_not_called()

    # Nowa cecha
    mock_client.post.return_value = {"product_feature": {"id": 11}}
    fid2 = ensure_product_feature(mock_client, "Materiał", features_cache, ["1"])
    assert fid2 == 11
    assert features_cache["materiał"] == 11

    # Nowa wartość cechy
    mock_client.post.return_value = {"product_feature_value": {"id": 102}}
    fvid = ensure_product_feature_value(mock_client, 11, "Stal", values_cache, ["1"])
    assert fvid == 102
    assert values_cache[(11, "stal")] == 102

    # Sprawdzenie w payloadzie
    item = ProductItem(
        product_id="1",
        name="Klamka",
        description="",
        description_short="",
        price_netto=Decimal("10.000000"),
        price_brutto=Decimal("12.30"),
        vat_rate=Decimal("23"),
        weight=Decimal("1.000000"),
    )
    payload = build_product_payload(
        product=item,
        default_category_id=2,
        associated_category_ids=[2],
        active_languages=["1"],
        product_features=[{"id": "11", "id_feature_value": "102"}],
    )
    assert payload["associations"]["product_features"] == [{"id": "11", "id_feature_value": "102"}]


# ==============================================================================
# 8. TESTY POPRAWEK CODE REVIEW (PR #70 / Issue #66)
# ==============================================================================

def test_dirty_checking_detects_description_and_short_change():
    """Weryfikuje, że zmiana opisu lub krótkiego opisu jest wykrywana przez dirty-checking."""
    item = ProductItem(
        product_id="10",
        name="Klamka mosiężna",
        description="<p>Nowy pełny opis produktu</p>",
        description_short="<p>Nowy krótki opis</p>",
        price_netto=Decimal("25.000000"),
        price_brutto=Decimal("30.75"),
        vat_rate=Decimal("23"),
        weight=Decimal("0.500000"),
    )

    existing_same = {
        "id": 55,
        "reference": "pandomu-10",
        "name": "Klamka mosiężna",
        "price": "25.000000",
        "id_tax_rules_group": "1",
        "id_category_default": "2",
        "weight": "0.500000",
        "active": "1",
        "description": "<p>Nowy pełny opis produktu</p>",
        "description_short": "<p>Nowy krótki opis</p>",
    }
    assert is_product_dirty(existing_same, item, default_category_id=2) is False

    # Zmiana opisu
    existing_old_desc = dict(existing_same, description="<p>Stary opis</p>")
    assert is_product_dirty(existing_old_desc, item, default_category_id=2) is True

    # Zmiana opisu krótkiego
    existing_old_short = dict(existing_same, description_short="<p>Stary krótki</p>")
    assert is_product_dirty(existing_old_short, item, default_category_id=2) is True


def test_malformed_record_type_does_not_abort_validation():
    """Weryfikuje, że rekord o nieprawidłowym typie (string, int, None) nie przerywa walidacji."""
    with tempfile.TemporaryDirectory() as tmp_dir:
        json_file = Path(tmp_dir) / "test_products.json"
        content = [
            "niepoprawny_string_zamiast_slownika",
            42,
            None,
            {
                "product_id": "99",
                "name": "Poprawny produkt",
                "price_netto": 10.0,
                "vat_rate": 23.0,
                "weight": 1.0,
                "category_paths": [["Drzwi"]],
            },
        ]
        json_file.write_text(json.dumps(content), encoding="utf-8")

        errs: List[str] = []
        items = load_and_validate_products(json_file, validation_errors=errs)
        assert len(items) == 1
        assert items[0].product_id == "99"
        assert len(errs) == 3
        assert any("niepoprawny_string_zamiast_slownika" in e or "str" in e for e in errs)


def test_stock_not_reset_for_existing_product_in_orchestrator():
    """Weryfikuje, że ponowny import istniejącego produktu nie resetuje bieżącego stanu ze sklepu."""
    mock_client = MagicMock()

    def mock_get(resource, *args, **kwargs):
        if resource == "products" and args:
            return {
                "product": {
                    "id": str(args[0]),
                    "reference": "pandomu-1",
                    "price": "10.000000",
                    "id_tax_rules_group": "1",
                    "id_category_default": "2",
                    "weight": "1.000000",
                    "active": "1",
                    "name": "Produkt 1",
                    "description": "",
                    "description_short": "",
                    "associations": {"categories": [{"id": "2"}]},
                }
            }
        return {
            "languages": [{"id": 1, "iso_code": "pl"}],
            "products": [{"id": 10, "reference": "pandomu-1", "price": "10.000000", "id_tax_rules_group": "1", "id_category_default": "2", "weight": "1.000000", "active": "1", "name": "Produkt 1", "description": "", "description_short": ""}],
            "combinations": [],
            "product_options": [],
            "product_option_values": [],
            "product_features": [],
            "product_feature_values": [],
            "stock_availables": [{"id": 100, "id_product": 10, "id_product_attribute": "0", "quantity": "4", "out_of_stock": "0", "depends_on_stock": "0"}],
        }.get(resource, [])

    mock_client.get.side_effect = mock_get

    with tempfile.TemporaryDirectory() as tmp_dir:
        cat_file = Path(tmp_dir) / "category_mapping.json"
        cat_file.write_text(json.dumps({"Drzwi": 2, "ROOT": 2}), encoding="utf-8")
        prod_file = Path(tmp_dir) / "products.json"
        prod_file.write_text(json.dumps([{
            "product_id": "1",
            "name": "Produkt 1",
            "price_netto": 10.0,
            "vat_rate": 23.0,
            "weight": 1.0,
            "category_paths": [["Drzwi"]],
            # stock jest None -> stan powinien pozostać 4 (ze sklepu), a nie deterministyczny tot_stock
        }]), encoding="utf-8")
        prod_map_file = Path(tmp_dir) / "product_mapping.json"

        stats, mapping = import_products(
            client=mock_client,
            products_file=prod_file,
            category_mapping_file=cat_file,
            product_mapping_file=prod_map_file,
        )

        assert stats.created == 0
        assert stats.skipped == 1
        assert stats.errors == 0
        # Stan 4 był zgodny z targetem (4 ze sklepu), więc nie wykonano PUT
        assert stats.stocks_updated == 0
        assert stats.stocks_skipped == 1


def test_product_identity_by_reference_only_ignores_stale_id_mapping():
    """Weryfikuje, że brak produktu po referencji nie powoduje fallbacku do starego ID z mapowania."""
    mock_client = MagicMock()
    # W sklepie nie ma produktu o referencji pandomu-999
    # Ale w starym product_mapping jest "999": 10
    mock_client.get.side_effect = lambda resource, *args, **kwargs: {
        "languages": [{"id": 1, "iso_code": "pl"}],
        "products": [{"id": 10, "reference": "pandomu-inny-produkt", "price": "10.000000", "id_tax_rules_group": "1", "id_category_default": "2", "weight": "1.000000", "active": "1", "name": "Inny", "description": "", "description_short": ""}],
        "combinations": [],
        "product_options": [],
        "product_option_values": [],
        "product_features": [],
        "product_feature_values": [],
        "stock_availables": [{"id": 100, "id_product": 11, "id_product_attribute": "0", "quantity": "0", "out_of_stock": "0", "depends_on_stock": "0"}],
    }.get(resource, [])
    mock_client.post.return_value = {"product": {"id": 11}}

    with tempfile.TemporaryDirectory() as tmp_dir:
        cat_file = Path(tmp_dir) / "category_mapping.json"
        cat_file.write_text(json.dumps({"Drzwi": 2, "ROOT": 2}), encoding="utf-8")
        prod_file = Path(tmp_dir) / "products.json"
        prod_file.write_text(json.dumps([{
            "product_id": "999",
            "name": "Nowy produkt 999",
            "price_netto": 15.0,
            "vat_rate": 23.0,
            "weight": 1.0,
            "category_paths": [["Drzwi"]],
        }]), encoding="utf-8")
        prod_map_file = Path(tmp_dir) / "product_mapping.json"
        # Stare, nieaktualne mapowanie
        prod_map_file.write_text(json.dumps({"999": 10, "pandomu-999": 10}), encoding="utf-8")

        stats, mapping = import_products(
            client=mock_client,
            products_file=prod_file,
            category_mapping_file=cat_file,
            product_mapping_file=prod_map_file,
        )

        # Produkt 999 NIE został dopasowany do ID 10! Został utworzony jako nowy (POST)
        assert stats.created == 1
        assert stats.updated == 0
        mock_client.post.assert_called()
        assert mapping["999"] == 11


def test_dirty_checking_detects_category_add_and_remove():
    """Weryfikuje, że dodanie i usunięcie dodatkowej kategorii jest wykrywane przy niezmienionej kategorii domyślnej."""
    item = ProductItem(
        product_id="1",
        name="Klamka",
        description="",
        description_short="",
        price_netto=Decimal("10.000000"),
        price_brutto=Decimal("12.30"),
        vat_rate=Decimal("23"),
        weight=Decimal("1.000000"),
    )

    existing_base = {
        "id": 10,
        "name": "Klamka",
        "price": "10.000000",
        "id_tax_rules_group": "1",
        "id_category_default": "2",
        "weight": "1.000000",
        "active": "1",
        "associations": {
            "categories": [{"id": "2"}, {"id": "5"}]
        },
    }

    # Ten sam zestaw kategorii [2, 5] -> clean
    assert is_product_dirty(existing_base, item, default_category_id=2, associated_category_ids=[2, 5]) is False

    # Dodanie nowej kategorii [2, 5, 8] -> dirty
    assert is_product_dirty(existing_base, item, default_category_id=2, associated_category_ids=[2, 5, 8]) is True

    # Usunięcie kategorii [2] -> dirty
    assert is_product_dirty(existing_base, item, default_category_id=2, associated_category_ids=[2]) is True


def test_features_lifecycle_dirty_checking_and_empty_xml():
    """Weryfikuje cykl życia cech: dodanie pierwszej, zmianę, usunięcie ostatniej i serializację XML."""
    item = ProductItem(
        product_id="1",
        name="Klamka",
        description="",
        description_short="",
        price_netto=Decimal("10.000000"),
        price_brutto=Decimal("12.30"),
        vat_rate=Decimal("23"),
        weight=Decimal("1.000000"),
    )

    # 1. Brak cech w sklepie -> dodanie pierwszej cechy
    existing_no_features = {
        "id": 10,
        "name": "Klamka",
        "price": "10.000000",
        "id_tax_rules_group": "1",
        "id_category_default": "2",
        "weight": "1.000000",
        "active": "1",
        "associations": {"product_features": []},
    }
    target_feature = [{"id": "1", "id_feature_value": "10"}]
    assert is_product_dirty(existing_no_features, item, default_category_id=2, product_features=target_feature) is True

    # 2. Ta sama cecha -> clean
    existing_with_feature = {
        "id": 10,
        "name": "Klamka",
        "price": "10.000000",
        "id_tax_rules_group": "1",
        "id_category_default": "2",
        "weight": "1.000000",
        "active": "1",
        "associations": {"product_features": [{"id": "1", "id_feature_value": "10"}]},
    }
    assert is_product_dirty(existing_with_feature, item, default_category_id=2, product_features=target_feature) is False

    # 3. Zmiana wartości cechy -> dirty
    target_changed_feature = [{"id": "1", "id_feature_value": "20"}]
    assert is_product_dirty(existing_with_feature, item, default_category_id=2, product_features=target_changed_feature) is True

    # 4. Usunięcie ostatniej cechy (target = []) -> dirty
    assert is_product_dirty(existing_with_feature, item, default_category_id=2, product_features=[]) is True

    # 5. Pusta lista cech w build_product_payload daje pusty tag <product_features /> w XML
    payload_empty_features = build_product_payload(
        product=item,
        default_category_id=2,
        associated_category_ids=[2],
        active_languages=["1"],
        product_id=10,
        existing_full_product=existing_with_feature,
        product_features=[],
    )
    assert payload_empty_features["associations"]["product_features"] == []
    xml = dict_to_prestashop_xml("products", payload_empty_features)
    assert "<product_features />" in xml


def test_combination_attributes_dirty_checking_and_payload():
    """Weryfikuje detekcję zmian atrybutów wariantu oraz zachowanie zdjęć w payloadzie kombinacji."""
    variant = VariantItem(
        variant_id="1",
        name="Rozmiar 50",
        price_modifier=Decimal("0.000000"),
    )

    existing_comb = {
        "id": 50,
        "price": "0.000000",
        "default_on": "1",
        "associations": {
            "product_option_values": [{"id": "10"}, {"id": "20"}],
            "images": [{"id": "101"}, {"id": "102"}],
        },
    }

    # 1. Te same atrybuty [10, 20] -> clean
    assert is_combination_dirty(existing_comb, variant, is_default=True, option_value_ids=[10, 20]) is False

    # 2. Zmiana wartości jednego z atrybutów [10, 30] -> dirty
    assert is_combination_dirty(existing_comb, variant, is_default=True, option_value_ids=[10, 30]) is True

    # 3. Usunięcie jednego z atrybutów [10] -> dirty
    assert is_combination_dirty(existing_comb, variant, is_default=True, option_value_ids=[10]) is True

    # 4. Payload kombinacji zachowuje powiązane zdjęcia
    payload = build_combination_payload(
        product_id=10,
        source_product_id="1",
        variant=variant,
        option_value_ids=[10, 30],
        is_default=True,
        combination_id=50,
        existing_full_combination=existing_comb,
    )
    assert payload["associations"]["product_option_values"] == [{"id": "10"}, {"id": "30"}]
    assert payload["associations"]["images"] == [{"id": "101"}, {"id": "102"}]


def test_dirty_checking_association_order_invariance():
    """Weryfikuje, że różna kolejność identyfikatorów w asocjacjach nie powoduje fałszywego PUT."""
    item = ProductItem(
        product_id="1",
        name="Klamka",
        description="",
        description_short="",
        price_netto=Decimal("10.000000"),
        price_brutto=Decimal("12.30"),
        vat_rate=Decimal("23"),
        weight=Decimal("1.000000"),
    )

    existing_prod = {
        "id": 10,
        "name": "Klamka",
        "price": "10.000000",
        "id_tax_rules_group": "1",
        "id_category_default": "2",
        "weight": "1.000000",
        "active": "1",
        "associations": {
            "categories": [{"id": "5"}, {"id": "2"}, {"id": "9"}],
            "product_features": [{"id": "2", "id_feature_value": "20"}, {"id": "1", "id_feature_value": "10"}],
        },
    }

    # Inna kolejność kategorii [2, 9, 5] oraz cech -> clean (False)
    target_cats = [2, 9, 5]
    target_feats = [{"id": "1", "id_feature_value": "10"}, {"id": "2", "id_feature_value": "20"}]
    assert is_product_dirty(
        existing_prod,
        item,
        default_category_id=2,
        associated_category_ids=target_cats,
        product_features=target_feats,
    ) is False

    # Inna kolejność wartości opcji kombinacji [20, 10] vs [10, 20] -> clean (False)
    variant = VariantItem(variant_id="1", name="W", price_modifier=Decimal("0.000000"))
    existing_comb = {
        "id": 50,
        "price": "0.000000",
        "default_on": "1",
        "associations": {"product_option_values": [{"id": "20"}, {"id": "10"}]},
    }
    assert is_combination_dirty(existing_comb, variant, is_default=True, option_value_ids=[10, 20]) is False


def test_get_error_protects_associations_and_continues_loop():
    """Weryfikuje, że błąd GET nie wywołuje PUT na niepełnych danych i nie przerywa pętli importu."""
    mock_client = MagicMock()

    def mock_get(resource, *args, **kwargs):
        if resource == "products" and args:
            # Pierwszy produkt zwraca błąd API przy GET
            if args[0] == 10:
                raise RuntimeError("Błąd połączenia z API przy GET produktu 10")
            # Drugi produkt zwraca poprawny słownik
            return {
                "product": {
                    "id": "20",
                    "reference": "pandomu-2",
                    "price": "10.000000",
                    "id_tax_rules_group": "1",
                    "id_category_default": "2",
                    "weight": "1.000000",
                    "active": "1",
                    "name": "Produkt 2",
                    "description": "",
                    "description_short": "",
                    "associations": {"categories": [{"id": "2"}]},
                }
            }
        return {
            "languages": [{"id": 1, "iso_code": "pl"}],
            "products": [
                {"id": 10, "reference": "pandomu-1", "price": "10.000000", "id_tax_rules_group": "1", "id_category_default": "2", "weight": "1.000000", "active": "1", "name": "Produkt 1", "description": "", "description_short": ""},
                {"id": 20, "reference": "pandomu-2", "price": "10.000000", "id_tax_rules_group": "1", "id_category_default": "2", "weight": "1.000000", "active": "1", "name": "Produkt 2", "description": "", "description_short": ""},
            ],
            "combinations": [],
            "product_options": [],
            "product_option_values": [],
            "product_features": [],
            "product_feature_values": [],
            "stock_availables": [],
        }.get(resource, [])

    mock_client.get.side_effect = mock_get

    with tempfile.TemporaryDirectory() as tmp_dir:
        cat_file = Path(tmp_dir) / "category_mapping.json"
        cat_file.write_text(json.dumps({"Drzwi": 2, "ROOT": 2}), encoding="utf-8")
        prod_file = Path(tmp_dir) / "products.json"
        prod_file.write_text(json.dumps([
            {"product_id": "1", "name": "Produkt 1", "price_netto": 10.0, "vat_rate": 23.0, "weight": 1.0, "category_paths": [["Drzwi"]]},
            {"product_id": "2", "name": "Produkt 2", "price_netto": 10.0, "vat_rate": 23.0, "weight": 1.0, "category_paths": [["Drzwi"]]},
        ]), encoding="utf-8")
        prod_map_file = Path(tmp_dir) / "product_mapping.json"

        stats, mapping = import_products(
            client=mock_client,
            products_file=prod_file,
            category_mapping_file=cat_file,
            product_mapping_file=prod_map_file,
        )

        # Produkt 1 zgłosił błąd pobrania, produkt 2 został pominięty (clean)
        assert stats.errors == 1
        assert stats.skipped == 1
        assert stats.updated == 0
        assert stats.created == 0
        # PUT nie został wywołany dla produktu 1
        mock_client.put.assert_not_called()
