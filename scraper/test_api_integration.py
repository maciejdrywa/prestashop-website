"""Odbiór D2.1–D2.2 na odrębnej instancji wspólnego deploy/compose.yaml."""

import json
import os
import subprocess
import uuid
from pathlib import Path

import pytest
from category_import import import_categories
from category_import.prestashop_sync import (
    fetch_active_languages,
    fetch_existing_categories,
)
from prestashop_api import (
    PrestaShopAPIError,
    PrestaShopClient,
    PrestaShopConnectionError,
)
from test_api_client import check_permissions

pytestmark = pytest.mark.integration
ROOT = Path(__file__).resolve().parents[1]


@pytest.fixture
def client():
    url = os.getenv("D21_TEST_API_URL")
    if not url:
        pytest.skip("Ustaw D21_TEST_API_URL i PRESTASHOP_API_KEY dla odrębnej instancji D2.")
    if url not in {"http://localhost:18080/api", "https://localhost:18443/api"}:
        pytest.fail(
            "Test zapisu wymaga odrębnej instancji Compose D2 na porcie 18080 lub 18443."
        )
    # Osobna domena SSL i port proxy; nigdy nie zmienia konfiguracji zwykłego sklepu.
    tls = 1 if url.startswith("https:") else 0
    code = (
        'require "/opt/shop/init/lib/prestashop.php"; ShopInit\\boot(); '
        'Db::getInstance()->update("shop_url", ["domain"=>"localhost:18080", "domain_ssl"=>"localhost:18443"]); '
        f'Configuration::updateValue("PS_SSL_ENABLED", {tls}); '
        f'Configuration::updateValue("PS_SSL_ENABLED_EVERYWHERE", {tls});'
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
    return PrestaShopClient(
        url, os.environ["PRESTASHOP_API_KEY"], verify_ssl=True, get_retries=0
    )


def record(name, parent=None):
    return {
        "name": name,
        "category_path": [*parent["category_path"], name] if parent else [name],
        "source_url": "https://d21.example/" + name,
        "parent_source_url": parent["source_url"] if parent else None,
    }


def sync(tmp_path, client, records):
    source = tmp_path / "source.json"
    source.write_text(json.dumps(records, ensure_ascii=False), encoding="utf-8")
    return import_categories(client, source, tmp_path / "mapping.json")


def cleanup(ids):
    """Uprawnienia importera pozostają minimalne; sprzątanie przez lokalny model PHP."""
    ids = [int(value) for value in ids]
    if not ids:
        return
    code = (
        'require "/opt/shop/init/lib/prestashop.php"; ShopInit\\boot(); '
        "foreach (" + json.dumps(list(reversed(ids))) + " as $id) { "
        "$category = new Category($id); if (Validate::isLoadedObject($category)) $category->delete(); }"
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


def test_permissions_and_write_denial(client):
    check_permissions(client)
    with pytest.raises(PrestaShopAPIError) as denied:
        client.delete("categories", 2147483647)
    assert denied.value.status_code in {401, 403, 405}
    for resource in ["taxes", "tax_rules", "tax_rule_groups", "customers", "orders"]:
        with pytest.raises(PrestaShopAPIError) as denied:
            client.post(resource, "<prestashop/>")
        assert denied.value.status_code in {401, 403, 405}


def test_actual_catalog_twice(client, tmp_path):
    source = json.loads((ROOT / "scraper-results/categories.json").read_text())
    first, mapping = sync(tmp_path, client, source)
    before = fetch_existing_categories(client)
    second, again = sync(tmp_path, client, source)
    after = fetch_existing_categories(client)
    assert first.errors == second.errors == 0
    assert second.created == second.updated == 0 and second.skipped == 80
    assert len(before) == len(after) and mapping == again
    ids = {cat["id"]: cat for cat in after}
    for item in source:
        parent = mapping[item["parent_source_url"]] if item["parent_source_url"] else 2
        assert ids[mapping[item["source_url"]]]["id_parent"] == parent


def test_rename_move_preserve_description_and_lost_response(client, tmp_path):
    prefix = "D21-" + uuid.uuid4().hex[:8]
    a = record(prefix + "-A")
    b = record(prefix + "-B")
    child = record(prefix + "-child", a)
    ids = []
    try:
        stats, mapping = sync(tmp_path, client, [a, b, child])
        assert stats.created == 3 and stats.errors == 0
        ids = [mapping[item["source_url"]] for item in [a, b, child]]
        full = client.get("categories", ids[2])["category"]
        from category_import.prestashop_sync import build_category_payload
        from category_import.validator import validate_category_record

        payload = build_category_payload(
            validate_category_record(child, 0),
            ids[0],
            ids[2],
            fetch_active_languages(client),
            full,
        )
        payload["description"] = {
            language: "<p>Zachowaj opis</p>"
            for language in fetch_active_languages(client)
        }
        client.put("categories", ids[2], payload)
        renamed = {
            **child,
            "name": prefix + "-renamed",
            "category_path": [b["name"], prefix + "-renamed"],
            "parent_source_url": b["source_url"],
        }
        real_put = client.put

        def lost(*args, **kwargs):
            real_put(*args, **kwargs)
            raise PrestaShopConnectionError("utracona odpowiedź po zapisie")

        client.put = lost
        stats, _ = sync(tmp_path, client, [a, b, renamed])
        assert stats.errors == 1
        client.put = real_put
        stats, result = sync(tmp_path, client, [a, b, renamed])
        assert stats.errors == 0 and result[child["source_url"]] == ids[2]
        saved = client.get("categories", ids[2])["category"]
        assert int(saved["id_parent"]) == ids[1]
        assert "Zachowaj opis" in str(saved["description"])
    finally:
        cleanup(ids)


def test_post_lost_after_commit_and_parent_cascade(client, tmp_path):
    a = record("D21-" + uuid.uuid4().hex[:8])
    child = record(a["name"] + "-child", a)
    ids = []
    real_post = client.post

    def lost(*args, **kwargs):
        result = real_post(*args, **kwargs)
        ids.append(int(result["category"]["id"]))
        raise PrestaShopConnectionError("utracona odpowiedź POST")

    try:
        client.post = lost
        stats, _ = sync(tmp_path, client, [a, child])
        assert stats.errors == 2 and len(ids) == 1
        client.post = real_post
        stats, mapping = sync(tmp_path, client, [a, child])
        assert stats.errors == 0 and stats.created == 1
        assert mapping[a["source_url"]] == ids[0]
        ids.append(mapping[child["source_url"]])
    finally:
        client.post = real_post
        cleanup(ids)
