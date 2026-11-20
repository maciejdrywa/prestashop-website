"""Testy orkiestratora D2.5: granice wznowienia, aktywacja i izolacja danych."""

import json
import os
import subprocess
import sys
from unittest.mock import Mock

import pytest
from benchmark_catalog import generate
from catalog_import.config import ROOT, Compose
from catalog_import.runner import activate, read_manifest, validate_inputs
from import_catalog import main
from import_state import instance_lock
from prestashop_api import PrestaShopAPIError, PrestaShopConnectionError
from test_product_import import FakeAPI


def test_real_catalog_preflight_is_offline():
    products, categories, images = validate_inputs(
        ROOT / "scraper-results/products.json", ROOT / "scraper-results/categories.json"
    )
    assert len(products) == 574 and len(categories) == 80 and len(images) == 574


def test_dry_run_bootstrap_never_starts_compose_or_writes(
    tmp_path, monkeypatch, capsys
):
    secret = "a" * 32
    monkeypatch.setenv("PRESTASHOP_API_URL", "https://localhost:18443/api")
    monkeypatch.setenv("PRESTASHOP_API_KEY", secret)
    env = tmp_path / "private.env"
    env.write_text(
        f"PRESTASHOP_API_URL=https://localhost:18443/api\nPRESTASHOP_API_KEY={secret}\n"
    )
    monkeypatch.setattr(Compose, "run", Mock(side_effect=AssertionError("compose")))
    monkeypatch.setattr(
        "catalog_import.runner.atomic_json", Mock(side_effect=AssertionError("write"))
    )
    monkeypatch.setattr(
        "catalog_import.runner.ImageCache.get",
        Mock(side_effect=AssertionError("download")),
    )
    assert (
        main(
            [
                "--bootstrap",
                "--resume",
                "--dry-run",
                "--env-file",
                str(env),
                "--state-dir",
                str(tmp_path / "state"),
                "--cache-dir",
                str(tmp_path / "cache"),
            ]
        )
        == 0
    )
    assert not (tmp_path / "state").exists() and not (tmp_path / "cache").exists()
    assert secret not in capsys.readouterr().out


def test_resume_rejects_changes_and_sync_requires_finished_previous_run(tmp_path):
    path = tmp_path / "state.json"
    original = {"products": "a", "categories": "b"}
    state = read_manifest(path, "https://shop/api", original, False)
    state["status"] = "complete"
    path.write_text(json.dumps(state))
    changed = {**original, "products": "c"}
    with pytest.raises(ValueError, match="--sync"):
        read_manifest(path, "https://shop/api", changed, False)
    assert read_manifest(path, "https://shop/api", changed, True)["inputs"] == changed
    state["status"] = "failed"
    path.write_text(json.dumps(state))
    assert read_manifest(path, "https://shop/api", changed, True)["inputs"] == changed
    (tmp_path / "products.state.json").write_text(
        json.dumps({"records": {"product": {"state": "pending", "id": None}}})
    )
    with pytest.raises(ValueError, match="niepewne POST"):
        read_manifest(path, "https://shop/api", changed, True)


def test_manifest_rejects_wrong_backend_and_target(tmp_path):
    path = tmp_path / "state.json"
    state = read_manifest(path, "https://shop/api", {}, False)
    path.write_text(json.dumps(state))
    with pytest.raises(ValueError, match="backend"):
        read_manifest(path, "https://other/api", {}, False)
    state["backend"] = "php"
    path.write_text(json.dumps(state))
    with pytest.raises(ValueError, match="backend"):
        read_manifest(path, "https://shop/api", {}, False)


def activation_fixture(tmp_path):
    api = FakeAPI()
    api.rows = {
        pid: {
            "id": pid,
            "id_shop_default": "1",
            "active": "0",
            "reference": f"pandomu-{pid}",
        }
        for pid in [1, 2]
    }
    state = tmp_path / "products.json"
    state.write_text(
        json.dumps(
            {
                "records": {
                    "products:pandomu-1": {"id": 1, "owned": True},
                    "products:pandomu-2": {"id": 2, "owned": False},
                }
            }
        )
    )
    manifest = {"activation": {}}
    report = {"products": [{"source_id": str(pid), "id": pid} for pid in [1, 2]]}
    return api, state, manifest, report


def test_activation_only_owns_new_products_and_preserves_later_admin_change(tmp_path):
    api, state, manifest, report = activation_fixture(tmp_path)
    assert (
        activate(
            api,
            {"shop_id": 1, "default_language_id": 1},
            report,
            state,
            manifest,
            tmp_path / "catalog.json",
        )
        == 1
    )
    assert api.rows[1]["active"] == "1" and api.rows[2]["active"] == "0"
    api.rows[1]["active"] = "0"
    assert (
        activate(
            api,
            {"shop_id": 1, "default_language_id": 1},
            report,
            state,
            manifest,
            tmp_path / "catalog.json",
        )
        == 0
    )
    assert api.rows[1]["active"] == "0"


def test_lost_activation_response_resumes_without_second_put(tmp_path):
    api, state, manifest, report = activation_fixture(tmp_path)
    original = api.put

    def lose(*args, **kwargs):
        original(*args, **kwargs)
        raise PrestaShopConnectionError("lost after commit")

    api.put = lose
    with pytest.raises(PrestaShopConnectionError):
        activate(
            api,
            {"shop_id": 1, "default_language_id": 1},
            report,
            state,
            manifest,
            tmp_path / "catalog.json",
        )
    manifest = json.loads((tmp_path / "catalog.json").read_text())
    api.put = original
    assert (
        activate(
            api,
            {"shop_id": 1, "default_language_id": 1},
            report,
            state,
            manifest,
            tmp_path / "catalog.json",
        )
        == 0
    )
    assert api.puts == 1 and manifest["activation"]["1"]["state"] == "done"


def test_catalog_lock_is_reentrant_only_when_explicit_and_blocks_other_process():
    identity = "catalog-test-process-lock"
    with instance_lock(identity, reentrant=True):
        with instance_lock(identity):
            pass
        code = (
            "from import_state import instance_lock; from prestashop_api import PrestaShopAPIError\ntry:\n with instance_lock("
            + repr(identity)
            + "): pass\nexcept PrestaShopAPIError:\n raise SystemExit(7)"
        )
        result = subprocess.run(
            [sys.executable, "-c", code],
            env={**os.environ, "PYTHONPATH": str(ROOT / "scraper")},
            capture_output=True,
        )
        assert result.returncode == 7
    with instance_lock(identity):
        with pytest.raises(PrestaShopAPIError):
            with instance_lock(identity):
                pass


def test_compose_diagnostics_redact_secrets_and_authorization(tmp_path, monkeypatch):
    compose = Compose(
        tmp_path / "env",
        {"DB_PASSWORD": "db-secret", "PRESTASHOP_API_KEY": "k" * 32},
        tmp_path / "source",
    )
    monkeypatch.setattr(
        subprocess,
        "run",
        Mock(
            return_value=type(
                "Result",
                (),
                {
                    "returncode": 1,
                    "stdout": "db-secret " + ("k" * 32),
                    "stderr": "Authorization: Basic encoded\nERROR: failed",
                },
            )()
        ),
    )
    with pytest.raises(ValueError) as caught:
        compose.run("exec")
    assert "db-secret" not in str(caught.value) and "k" * 32 not in str(caught.value)
    assert "Authorization" not in str(caught.value) and "ERROR: failed" in str(
        caught.value
    )


def test_benchmark_is_separate_and_reproduces_catalog_proportions(tmp_path):
    source = ROOT / "scraper-results/products.json"
    before = source.read_bytes()
    result = generate(
        source, ROOT / "scraper-results/categories.json", tmp_path / "benchmark"
    )
    products, _, images = validate_inputs(
        tmp_path / "benchmark/products.json", tmp_path / "benchmark/categories.json"
    )
    assert (
        result["products"] == len(products) == 1000 and result["combinations"] == 2211
    )
    assert sum(len(p["urls"]) for p in images) == 2434
    assert source.read_bytes() == before
    with pytest.raises(ValueError):
        generate(
            source,
            ROOT / "scraper-results/categories.json",
            ROOT / "scraper-results/benchmark",
        )


def test_initial_stock_verification_handles_simple_products():
    from catalog_import.verify import verify_initial_stock

    source = {"product_id": "539", "price_plan": {"variants": []}}
    verify_initial_stock(source, {0: 5}, [], 5)
    with pytest.raises(ValueError, match="magazyn"):
        verify_initial_stock(source, {0: 4}, [], 5)


def test_initial_stock_verification_checks_combination_distribution():
    from catalog_import.verify import verify_initial_stock

    source = {
        "product_id": "5",
        "price_plan": {
            "variants": [
                {"source_id": "1", "reference": "one"},
                {"source_id": "2", "reference": "two"},
            ]
        },
    }
    saved = [{"id": 11, "reference": "one"}, {"id": 12, "reference": "two"}]
    verify_initial_stock(source, {0: 5, 11: 3, 12: 2}, saved, 5)
    with pytest.raises(ValueError, match="magazyn"):
        verify_initial_stock(source, {0: 5, 11: 2, 12: 3}, saved, 5)


def test_compact_checkpoint_preserves_all_values(tmp_path):
    from import_state import atomic_json

    path = tmp_path / "state.json"
    value = {
        "identity": "zażółć",
        "records": {"a": {"state": "pending", "id": None, "quantity": 0}},
    }
    atomic_json(path, value, compact=True)
    assert json.loads(path.read_text()) == value
    assert "\n" not in path.read_text().strip()


def test_input_snapshot_rejects_mid_run_changes_and_freezes_bytes(tmp_path):
    from catalog_import.runner import snapshot_inputs
    from image_import.cache import digest

    products, categories = tmp_path / "products.json", tmp_path / "categories.json"
    products.write_text('[{"id":1}]')
    categories.write_text("[]")
    fingerprints = {"products": digest(products), "categories": digest(categories)}
    snapshot_inputs(tmp_path / "snapshot", products, categories, fingerprints)
    products.write_text('[{"id":2}]')
    assert (tmp_path / "snapshot/products.json").read_text() == '[{"id":1}]'
    with pytest.raises(ValueError, match="zmieniły"):
        snapshot_inputs(tmp_path / "other", products, categories, fingerprints)


def test_selected_env_file_takes_precedence_over_inherited_defaults(
    tmp_path, monkeypatch
):
    from catalog_import.config import environment

    monkeypatch.setenv("PRESTASHOP_API_URL", "https://other/api")
    monkeypatch.setenv("PRESTASHOP_API_KEY", "a" * 32)
    path = tmp_path / "chosen.env"
    path.write_text(
        "PRESTASHOP_API_URL=https://chosen/api\nPRESTASHOP_API_KEY=" + ("b" * 32) + "\n"
    )
    values = environment(path)
    assert (
        values["PRESTASHOP_API_URL"] == "https://chosen/api"
        and values["PRESTASHOP_API_KEY"] == "b" * 32
    )


def test_other_instance_is_rejected_before_catalog_dependencies(monkeypatch):
    from catalog_import.runner import check_dependencies

    client = Mock()
    client.get_instance_identity.return_value = "other-instance"
    compose = Mock()
    preflight = Mock(side_effect=AssertionError("must not reach writes/dependencies"))
    monkeypatch.setattr("catalog_import.runner.preflight", preflight)
    with pytest.raises(ValueError, match="inną instalację"):
        check_dependencies(client, compose, "expected-instance", [], {})
    preflight.assert_not_called()
    compose.php.assert_not_called()


def test_container_with_other_source_files_requires_bootstrap():
    from catalog_import.runner import export_context

    compose = Mock()
    compose.php.return_value = json.dumps(
        {"source_hashes": {"products": "wrong", "categories": "same"}}
    )
    with pytest.raises(ValueError, match="--bootstrap"):
        export_context(compose, {"products": "expected", "categories": "same"})


def test_resume_guard_preserves_completed_fields_until_explicit_sync(tmp_path):
    from import_state import completed_records_guard
    from test_product_import import store

    api = FakeAPI()
    desired = {"reference": "pandomu-123", "price": "1"}
    store(tmp_path, api).sync("products", "123", {"reference": "pandomu-123"}, desired)
    api.rows[1]["price"] = "2"  # Edycja administratora po ukończeniu importu.
    with (
        completed_records_guard(True),
        pytest.raises(PrestaShopAPIError, match="--sync"),
    ):
        store(tmp_path, api).sync(
            "products", "123", {"reference": "pandomu-123"}, desired
        )
    assert api.rows[1]["price"] == "2" and api.puts == 0
    with completed_records_guard(False):
        store(tmp_path, api).sync(
            "products", "123", {"reference": "pandomu-123"}, desired
        )
    assert api.rows[1]["price"] == "1" and api.puts == 1
