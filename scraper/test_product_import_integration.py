"""Odbiór D2.3 na tej samej odrębnej instancji Compose co testy D2.1–D2.2."""

import copy
import json
import subprocess
import uuid
from pathlib import Path
from unittest.mock import Mock

import pytest
from category_import import import_categories
from prestashop_api import PrestaShopConnectionError
from product_import import import_products
from test_api_integration import client as shared_client

client = shared_client
pytestmark = pytest.mark.integration
ROOT = Path(__file__).resolve().parents[1]


@pytest.fixture
def batch(client, tmp_path):
    original = json.loads((ROOT / "scraper-results/products.json").read_text())
    simple = next(row for row in original if row["product_id"] == "539")
    combination = next(row for row in original if row["product_id"] == "5")
    token = str(int(uuid.uuid4().hex[:10], 16))
    data = [copy.deepcopy(combination if i == 0 else simple) for i in range(6)]
    for i, row in enumerate(data):
        row["product_id"] = "900" + token + str(i)
    feature = "D23-Material-" + token
    data[0]["features"] = [{"name": feature, "value": "Stal"}]
    data[1]["weight"] = None
    context = subprocess.run(
        [
            "docker",
            "exec",
            "--user",
            "www-data",
            "prestashop-d21-tests-shop-1",
            "php",
            "/opt/shop/init/export-import-context.php",
        ],
        capture_output=True,
        text=True,
        check=True,
    ).stdout
    (tmp_path / "context.json").write_text(context)
    import_categories(
        client, ROOT / "scraper-results/categories.json", tmp_path / "mapping.json"
    )
    source = tmp_path / "products.json"
    source.write_text(json.dumps(data, ensure_ascii=False))

    def run(**kwargs):
        return import_products(
            client,
            source,
            tmp_path / "mapping.json",
            tmp_path / "context.json",
            tmp_path / "state.json",
            **kwargs,
        )

    yield data, source, run
    refs = ["pandomu-" + row["product_id"] for row in data]
    # Sprzątanie wyłącznie własnych fixture’ów na instancji testowej, bez DELETE w kluczu API.
    code = (
        'require "/opt/shop/init/lib/prestashop.php"; ShopInit\\boot(); '
        "foreach ("
        + json.dumps(refs)
        + ' as $ref) { $id=(int) Db::getInstance()->getValue("SELECT id_product FROM "._DB_PREFIX_."product WHERE reference=\'".pSQL($ref)."\'"); '
        "if ($id) (new Product($id))->delete(); } "
        '$ids=Db::getInstance()->executeS("SELECT DISTINCT id_feature FROM "._DB_PREFIX_."feature_lang WHERE name=\'".pSQL('
        + json.dumps(feature)
        + ')."\'"); '
        'foreach ($ids as $row) (new Feature((int)$row["id_feature"]))->delete();'
    )
    subprocess.run(
        [
            "docker",
            "exec",
            "--user",
            "www-data",
            "prestashop-d21-tests-shop-1",
            "php",
            "-r",
            code,
        ],
        check=True,
        capture_output=True,
        text=True,
    )


def full(client, resource, rid):
    return client.get(resource, rid, params={"display": "full"})[resource][0]


def test_features_combinations_stock_and_idempotence(client, batch):
    data, _, run = batch
    first = run()
    assert not first["errors"] and len(first["products"]) == 6
    assert first["resources"]["products"]["created"] == 6
    assert first["resources"]["combinations"]["created"] == 2
    assert first["resources"]["product_features"]["created"] == 1
    assert first["unavailable_products"] == 1 and first["demo_weights"] == 1
    for row in first["products"]:
        saved = full(client, "products", row["id"])
        assert saved["active"] == "0" and 0 <= row["quantity"] <= 10
    pid = first["products"][0]["id"]
    variants = client.get(
        "combinations", params={"display": "full", "filter[id_product]": f"[{pid}]"}
    )["combinations"]
    assert sum(row.get("default_on") == "1" for row in variants) == 1
    code = (
        'require "/opt/shop/init/lib/products.php"; $ctx=ShopInit\\boot(); ShopInit\\priceContext($ctx); '
        f"echo ShopInit\\productGross({pid}, false, $ctx);"
    )
    calculated = subprocess.run(
        [
            "docker",
            "exec",
            "--user",
            "www-data",
            "prestashop-d21-tests-shop-1",
            "php",
            "-r",
            code,
        ],
        capture_output=True,
        text=True,
        check=True,
    )
    assert calculated.stdout.strip() == "59.99"
    second = run()
    assert not second["errors"]
    assert second["resources"]["products"] == {"skipped": 6}
    assert not any(
        counts.get("created", 0) or counts.get("updated", 0)
        for counts in second["resources"].values()
    )
    assert [row["id"] for row in first["products"]] == [
        row["id"] for row in second["products"]
    ]


def test_update_and_preserve_existing_stock_and_metadata(client, batch):
    data, path, run = batch
    first = run()
    assert not first["errors"]
    saved_id = first["products"][1]["id"]
    stocks = client.get(
        "stock_availables",
        params={"filter[id_product]": f"[{saved_id}]", "display": "full"},
    )["stock_availables"]
    client.put("stock_availables", int(stocks[0]["id"]), {**stocks[0], "quantity": "0"})
    from import_state import ImportJournal
    from product_import.resources import Resources

    helper = Resources(
        client,
        ImportJournal(
            path.parent / "helper.json", client.api_url, client.get_instance_identity()
        ),
        {"shop_id": 1, "default_language_id": 1},
    )
    product = full(client, "products", saved_id)
    client.put(
        "products",
        saved_id,
        helper.payload("products", product, {"meta_title": {"1": "Zachowaj tytuł"}}),
    )
    data[1].update(
        name="D23 nowa nazwa",
        description="<p>Zmieniony opis</p>",
        price_brutto="123.00",
        price_netto="100.000000",
    )
    path.write_text(json.dumps(data))
    second = run()
    assert not second["errors"] and second["resources"]["products"]["updated"] == 1
    saved = full(client, "products", saved_id)
    assert saved["name"] == "D23 nowa nazwa" and saved["meta_title"] == "Zachowaj tytuł"
    assert saved["price"] == "100.000000" and second["products"][1]["quantity"] == 0
    assert second["products"][1]["seeded"] is False
    reset = run(reset_stock=True)
    assert not reset["errors"] and all(row["seeded"] for row in reset["products"])


def test_recover_post_and_put_after_server_commit(client, batch):
    data, path, run = batch
    sid = data[0]["product_id"]
    real_post = client.post

    def lost(resource, *args, **kwargs):
        result = real_post(resource, *args, **kwargs)
        if resource == "products":
            raise PrestaShopConnectionError("lost after commit")
        return result

    client.post = lost
    failed = run(source_ids={sid})
    assert len(failed["errors"]) == 1
    client.post = real_post
    resumed = run(source_ids={sid})
    assert not resumed["errors"] and resumed["products"][0]["seeded"]
    rid = resumed["products"][0]["id"]
    data[0]["name"] = "D23 wznowiona nazwa"
    path.write_text(json.dumps(data))
    real_put = client.put

    def lost_put(resource, *args, **kwargs):
        result = real_put(resource, *args, **kwargs)
        if resource == "products":
            raise PrestaShopConnectionError("lost PUT after commit")
        return result

    client.put = lost_put
    assert len(run(source_ids={sid})["errors"]) == 1
    client.put = real_put
    report = run(source_ids={sid})
    assert not report["errors"] and report["products"][0]["id"] == rid
    found = client.get(
        "products", params={"filter[reference]": f"[pandomu-{sid}]", "display": "full"}
    )["products"]
    assert len(found) == 1


def test_dry_run_has_no_writes(client, batch, monkeypatch):
    _, path, run = batch
    monkeypatch.setattr(
        client, "post", Mock(side_effect=AssertionError("POST in dry run"))
    )
    monkeypatch.setattr(
        client, "put", Mock(side_effect=AssertionError("PUT in dry run"))
    )
    report = run(dry_run=True)
    assert not report["errors"] and report["planned_combinations"] == 2
    assert not (path.parent / "state.json").exists()


def test_adopt_existing_reference_without_resetting_stock(client, batch):
    _, source, run = batch
    first = run()
    assert not first["errors"]
    pid = first["products"][1]["id"]
    product = full(client, "products", pid)
    # Własny produkt fixture staje się zastanym rekordem bez dziennika importera.
    from product_import.resources import Resources

    helper = Resources(client, None, {"shop_id": 1, "default_language_id": 1})
    client.put("products", pid, helper.payload("products", product, {"active": "1"}))
    stocks = helper.many("stock_availables", {"filter[id_product]": f"[{pid}]"})
    stock = next(row for row in stocks if row["id_product_attribute"] == "0")
    client.put(
        "stock_availables",
        int(stock["id"]),
        helper.payload("stock_availables", stock, {"quantity": "19"}),
    )
    (source.parent / "state.json").unlink()
    adopted = run()
    assert not adopted["errors"]
    assert adopted["resources"]["products"] == {"skipped": 6}
    assert all(not row["seeded"] for row in adopted["products"])
    assert adopted["products"][1]["quantity"] == 19
    assert full(client, "products", pid)["active"] == "1"
