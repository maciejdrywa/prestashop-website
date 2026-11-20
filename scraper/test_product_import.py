"""Testy reguł D2.3 oraz bezpiecznych zapisów; bez sieci i bez Dockera."""

import copy
import json
from decimal import Decimal
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock

import pytest
from bs4 import BeautifulSoup
from import_state import ImportJournal
from prestashop_api import PrestaShopAPIError, PrestaShopConnectionError
from product_import.planning import (
    distribute_stock,
    price_plan,
    product_slug,
    read_catalog,
    stock_plan,
    validate_product,
    weight_plan,
)
from product_import.resources import Resources
from scraper_elements.scrap_features import scrap_features

ROOT = Path(__file__).resolve().parents[1]


@pytest.fixture
def source():
    data = json.loads((ROOT / "scraper-results/products.json").read_text())
    return copy.deepcopy(next(p for p in data if p["product_id"] == "5"))


def test_real_catalog_contract():
    products, errors = read_catalog(ROOT / "scraper-results/products.json")
    assert not errors and len(products) == 574
    assert sum(len(p["price_plan"]["variants"]) for p in products) == 1269
    assert sum(len(p["price_plan"]["adjustments"]) for p in products) == 8
    assert sum(p["weight_origin"] == "demo" for p in products) == 98
    plan = stock_plan(products)
    assert len(plan) == 574 and sum(q == 0 for q in plan.values()) == 57
    assert all(0 <= q <= 10 for q in plan.values())
    assert stock_plan(list(reversed(products))) == plan


@pytest.mark.parametrize(
    "field,value",
    [
        ("product_id", True),
        ("product_id", "0"),
        ("weight", -1),
        ("price_brutto", float("nan")),
        ("vat_rate", 101),
        ("name", ""),
        ("category_paths", []),
        ("description", "<script>alert(1)</script>"),
        ("description_short", '<p onclick="alert(1)">x</p>'),
        ("features", [{"name": "A", "value": ""}]),
        ("variants", None),
    ],
)
def test_invalid_source_is_rejected(source, field, value):
    source[field] = value
    with pytest.raises(ValueError):
        validate_product(source)


def test_duplicate_ids_are_global_error(tmp_path, source):
    path = tmp_path / "products.json"
    path.write_text(json.dumps([source, source]))
    with pytest.raises(ValueError, match="powielone"):
        read_catalog(path)


def test_bad_record_does_not_hide_valid_records(tmp_path, source):
    invalid = {**source, "product_id": "999", "weight": -1}
    path = tmp_path / "products.json"
    path.write_text(json.dumps([source, invalid]))
    products, errors = read_catalog(path)
    assert len(products) == len(errors) == 1 and errors[0]["source_id"] == "999"


def test_decimal_price_and_negative_variant(source):
    source.update(price_netto="10", price_brutto="12.30", vat_rate="23")
    source["variants"] = [
        {
            "variant_id": "1",
            "attributes": {"Color": "Blue"},
            "price_modifier": "-2.000000",
            "price_netto": "8",
            "price_brutto": "9.84",
        }
    ]
    plan = price_plan(source)
    assert plan["net"] == "10.000000" and plan["variants"][0]["modifier"] == "-2.000000"


def test_half_cent_adjustment(source):
    source.update(price_netto="1.000001", price_brutto="1.23", vat_rate=23)
    source["variants"] = [
        {
            "variant_id": "1",
            "attributes": {"Color": "Blue"},
            "price_modifier": "0.004065",
            "price_netto": "1.004066",
            "price_brutto": "1.24",
        }
    ]
    plan = price_plan(source)
    assert plan["adjustments"]
    assert (
        (Decimal(plan["net"]) + Decimal(plan["variants"][0]["modifier"]))
        * Decimal("1.23")
    ).quantize(Decimal(".01")) == Decimal("1.24")


def test_duplicate_variant_and_groups(source):
    source["variants"].append(source["variants"][0])
    with pytest.raises(ValueError):
        price_plan(source)


def test_weight_rules_match_existing_php(source):
    source["weight"] = None
    assert weight_plan(source) == {
        "weight": "1.000000",
        "weight_origin": "demo",
        "weight_rule": "zamki drzwiowe",
    }
    source["weight"] = ".99"
    assert weight_plan(source)["weight_origin"] == "source"
    assert weight_plan(source)["weight"] == "0.990000"


@pytest.mark.parametrize("total,count", [(0, 3), (2, 5), (10, 3), (7, 1)])
def test_combination_stock_preserves_total(total, count):
    plan = distribute_stock(total, [str(v) for v in range(1, count + 1)])
    assert sum(plan.values()) == total and all(0 <= v <= 10 for v in plan.values())


def test_small_catalog_cannot_satisfy_ratio():
    with pytest.raises(ValueError):
        stock_plan([{"product_id": "1"}])


def test_features_from_structured_source_and_table():
    soup = BeautifulSoup(
        '<div id="product-details" data-product=\'{"features":[{"name":"Materiał","value":"Stal"}]}\'></div>',
        "html.parser",
    )
    assert scrap_features(soup) == [{"name": "Materiał", "value": "Stal"}]
    soup = BeautifulSoup(
        '<section class="product-features"><dl class="data-sheet"><dt class="name">Kolor</dt><dd class="value">Czarny</dd></dl></section>',
        "html.parser",
    )
    assert scrap_features(soup) == [{"name": "Kolor", "value": "Czarny"}]
    assert scrap_features(BeautifulSoup("<p>Bez cech</p>", "html.parser")) == []


class FakeAPI:
    api_url = "https://test.example/api"

    def __init__(self):
        self.rows = {}
        self.posts = 0
        self.puts = 0
        self.lose_post = False

    def _send(self, *a, **kw):
        return SimpleNamespace(
            content=b'<prestashop><product><reference/><price/><name><language id="1"/></name><description><language id="1"/></description><active/><meta_title><language id="1"/></meta_title><quantity read_only="true"/></product></prestashop>'
        )

    def get(self, resource, resource_id=None, params=None):
        if resource_id is not None:
            return {resource: [copy.deepcopy(self.rows[resource_id])]}
        return {resource: copy.deepcopy(list(self.rows.values()))}

    def post(self, resource, payload):
        self.posts += 1
        rid = max([0, *self.rows]) + 1
        self.rows[rid] = {"id": rid, "id_shop_default": "1", **copy.deepcopy(payload)}
        if self.lose_post:
            self.lose_post = False
            raise PrestaShopConnectionError("lost after commit")
        return {"product": {"id": rid}}

    def put(self, resource, rid, payload):
        self.puts += 1
        self.rows[rid] = {"id": rid, "id_shop_default": "1", **copy.deepcopy(payload)}
        return {"product": {"id": rid}}


def store(tmp_path, api):
    return Resources(
        api,
        ImportJournal(tmp_path / "state.json", api.api_url, "fixture"),
        {"shop_id": 1, "default_language_id": 1},
    )


def test_post_lost_after_commit_is_adopted_without_duplicate(tmp_path):
    api = FakeAPI()
    api.lose_post = True
    desired = {
        "reference": "pandomu-123",
        "price": "1.000000",
        "name": {"1": "Fixture"},
    }
    with pytest.raises(PrestaShopConnectionError):
        store(tmp_path, api).sync(
            "products", "123", {"reference": "pandomu-123"}, desired
        )
    saved, owned = store(tmp_path, api).sync(
        "products", "123", {"reference": "pandomu-123"}, desired
    )
    assert owned and saved["id"] == 1 and api.posts == 1 and api.puts == 0


def test_unknown_post_outcome_never_reposts(tmp_path):
    api = FakeAPI()
    api.post = Mock(side_effect=PrestaShopConnectionError("lost before commit"))
    desired = {"reference": "pandomu-123", "price": "1"}
    with pytest.raises(PrestaShopConnectionError):
        store(tmp_path, api).sync(
            "products", "123", {"reference": "pandomu-123"}, desired
        )
    with pytest.raises(PrestaShopAPIError, match="Niepewny wynik"):
        store(tmp_path, api).sync(
            "products", "123", {"reference": "pandomu-123"}, desired
        )
    assert api.post.call_count == 1


def test_put_preserves_unmanaged_fields_and_skips_unchanged(tmp_path):
    api = FakeAPI()
    api.rows[1] = {
        "id": 1,
        "id_shop_default": "1",
        "reference": "pandomu-123",
        "price": "1",
        "name": "Old",
        "meta_title": "Zachowaj",
        "active": "1",
        "quantity": "9",
    }
    desired = {"reference": "pandomu-123", "price": "2.000000", "name": {"1": "New"}}
    s = store(tmp_path, api)
    saved, owned = s.sync("products", "123", {"reference": "pandomu-123"}, desired)
    assert (
        not owned
        and saved["active"] == "1"
        and saved["meta_title"] == {"1": "Zachowaj"}
    )
    assert "quantity" not in saved
    store(tmp_path, api).sync("products", "123", {"reference": "pandomu-123"}, desired)
    assert api.puts == 1


def test_duplicate_reference_does_not_write(tmp_path):
    api = FakeAPI()
    api.rows = {i: {"id": i, "reference": "pandomu-123"} for i in [1, 2]}
    with pytest.raises(PrestaShopAPIError, match="Niejednoznaczna"):
        store(tmp_path, api).sync(
            "products", "123", {"reference": "pandomu-123"}, {"price": "1"}
        )
    assert api.posts == api.puts == 0


def test_html_format_change_is_not_silently_skipped(tmp_path):
    api = FakeAPI()
    api.rows[1] = {
        "id": 1,
        "id_shop_default": "1",
        "reference": "pandomu-123",
        "description": "<p>Opis</p>",
    }
    desired = {
        "reference": "pandomu-123",
        "description": {"1": "<p><strong>Opis</strong></p>"},
    }
    store(tmp_path, api).sync("products", "123", {"reference": "pandomu-123"}, desired)
    assert api.puts == 1
    assert api.rows[1]["description"] == desired["description"]


def test_null_feature_is_not_invented_as_text():
    soup = BeautifulSoup(
        '<div id="product-details" data-product=\'{"features":[{"name":"Materiał","value":null}]}\'></div>',
        "html.parser",
    )
    with pytest.raises(ValueError):
        scrap_features(soup)


def test_inactive_translations_are_preserved(tmp_path):
    api = FakeAPI()
    api.rows[1] = {
        "id": 1,
        "id_shop_default": "1",
        "reference": "pandomu-123",
        "name": {"1": "Stara", "2": "Keep inactive translation"},
    }
    desired = {"reference": "pandomu-123", "name": {"1": "Nowa"}}
    s = store(tmp_path, api)
    saved, _ = s.sync("products", "123", {"reference": "pandomu-123"}, desired)
    assert saved["name"] == {"1": "Nowa", "2": "Keep inactive translation"}
    store(tmp_path, api).sync("products", "123", {"reference": "pandomu-123"}, desired)
    assert api.puts == 1


@pytest.mark.parametrize(
    ("name", "expected"),
    [
        ("Drut ogrodniczy czarny 3,4mm x 52 m", "drut-ogrodniczy-czarny-34mm-x-52-m"),
        ("Łącznik [stal]: 1/2", "lacznik-stal-1-2"),
        ("???", "pandomu-5"),
        ("a" * 127 + " x", "a" * 127),
    ],
)
def test_product_slug_matches_native_rules(name, expected):
    assert product_slug(name, 5) == expected


@pytest.mark.parametrize("field,value", [("id_shop_default", "2")])
def test_refuse_product_from_other_shop(tmp_path, field, value):
    api = FakeAPI()
    helper = store(tmp_path, api)
    api.rows[1] = {"id": 1, "reference": "pandomu-5", field: value}
    with pytest.raises(PrestaShopAPIError, match="innego sklepu"):
        helper.sync(
            "products",
            "pandomu-5",
            {"reference": "pandomu-5"},
            {"reference": "pandomu-5"},
        )
    assert not api.posts


@pytest.fixture
def vat_store(monkeypatch):
    import time

    import product_import.sync as sync

    monkeypatch.setattr(sync, "fetch_active_languages", lambda _: ["1"])
    data = {
        "shops": [{"id": 1, "active": "1", "id_category": 2}],
        "currencies": [{"iso_code": "PLN", "active": "1", "deleted": "0"}],
        "countries": [{"id": 14, "iso_code": "PL", "active": "1"}],
        "taxes": [{"id": 1, "rate": "23.000", "active": "1", "deleted": "0"}],
        "tax_rule_groups": [{"id": 1, "active": "1", "deleted": "0"}],
        "tax_rules": [
            {
                "id_tax_rules_group": 1,
                "id_country": 14,
                "id_tax": 1,
                "id_state": 0,
                "zipcode_from": "0",
                "zipcode_to": "0",
                "behavior": 0,
            }
        ],
    }
    context = {
        "shop_id": 1,
        "identity": "fixture",
        "exported_at": int(time.time()),
        "currency": "PLN",
        "country": "PL",
        "weight_unit": "kg",
        "tax_enabled": True,
        "stock_enabled": True,
        "home_category_id": 2,
        "default_language_id": 1,
        "tax_group_ids": [1],
    }
    return SimpleNamespace(
        context=context, all=lambda key: data[key], schema=Mock(), client=Mock()
    ), data


def test_vat_requires_general_active_rule_for_shop(vat_store):
    from product_import.sync import preflight

    helper, data = vat_store
    products = [{"price_plan": {"vat": Decimal(23)}}]
    assert preflight(helper, "fixture", products)[1] == {Decimal(23): 1}
    data["tax_rules"][0]["zipcode_from"] = "80000"
    with pytest.raises(ValueError, match="reguły VAT"):
        preflight(helper, "fixture", products)
    data["tax_rules"][0]["zipcode_from"] = "0"
    data["taxes"][0]["active"] = "0"
    with pytest.raises(ValueError, match="reguły VAT"):
        preflight(helper, "fixture", products)
    data["taxes"][0]["active"] = "1"
    helper.context["tax_group_ids"] = []
    with pytest.raises(ValueError, match="reguły VAT"):
        preflight(helper, "fixture", products)


def test_vat_duplicate_rules_are_not_a_general_unique_rule(vat_store):
    from product_import.sync import preflight

    helper, data = vat_store
    data["tax_rules"].append(copy.deepcopy(data["tax_rules"][0]))
    with pytest.raises(ValueError, match="reguły VAT"):
        preflight(helper, "fixture", [{"price_plan": {"vat": Decimal(23)}}])


@pytest.mark.parametrize("status", [400, 401, 403, 404, 405])
@pytest.mark.parametrize(
    "resource,identity",
    [
        ("products", {"reference": "pandomu-123"}),
        ("product_features", {"name": {"1": "Materiał"}}),
        ("product_feature_values", {"id_feature": "3", "value": {"1": "Stal"}}),
        ("product_options", {"name": {"1": "Kolor"}}),
        ("product_option_values", {"id_attribute_group": "2", "name": {"1": "Czarny"}}),
    ],
)
def test_rejected_post_discards_pending_and_allows_restart(
    tmp_path, status, resource, identity
):
    from prestashop_api import RESOURCE_SINGULAR_MAP

    api = FakeAPI()
    singular = RESOURCE_SINGULAR_MAP[resource]
    api._send = Mock(
        return_value=SimpleNamespace(
            content=(
                f"<prestashop><{singular}><reference/><id_feature/><id_attribute_group/>"
                '<name><language id="1"/></name><value><language id="1"/></value>'
                f"</{singular}></prestashop>"
            ).encode()
        )
    )
    original_post = api.post
    rejection = PrestaShopAPIError("Rejected", status_code=status)
    calls = 0

    def reject_then_create(resource, payload):
        nonlocal calls
        calls += 1
        if calls == 1:
            raise rejection
        created = original_post(resource, payload)
        return {singular: created["product"]}

    api.post = reject_then_create
    with pytest.raises(PrestaShopAPIError) as caught:
        store(tmp_path, api).sync(resource, "fixture", identity, identity)
    assert caught.value is rejection
    # Odczyt z dysku potwierdza trwałe usunięcie wpisu, także dla nowego procesu.
    restarted = store(tmp_path, api)
    assert resource + ":fixture" not in restarted.journal.data["records"]
    saved, owned = restarted.sync(resource, "fixture", identity, identity)
    assert saved["id"] == 1 and owned and calls == 2
    assert restarted.journal.data["records"][resource + ":fixture"]["state"] == "done"


@pytest.mark.parametrize("status", [429, 500, 502, 503, None])
def test_uncertain_post_error_keeps_pending_and_never_reposts(tmp_path, status):
    api = FakeAPI()
    api.post = Mock(side_effect=PrestaShopAPIError("Uncertain", status_code=status))
    desired = {"reference": "pandomu-123"}
    with pytest.raises(PrestaShopAPIError):
        store(tmp_path, api).sync("products", "123", desired, desired)
    restarted = store(tmp_path, api)
    assert restarted.journal.data["records"]["products:123"]["state"] == "pending"
    with pytest.raises(PrestaShopAPIError, match="Niepewny wynik"):
        restarted.sync("products", "123", desired, desired)
    assert api.post.call_count == 1
