"""Testy grafu, konfliktów i wznowienia po rzeczywistych granicach zapisu."""

import copy
import json
from unittest.mock import Mock

import pytest
from category_import import import_categories, load_and_validate_categories
from import_state import instance_lock
from prestashop_api import (
    PrestaShopAPIError,
    PrestaShopConnectionError,
)


def category(name, parent=None):
    path = [*parent["category_path"], name] if parent else [name]
    return {
        "name": name,
        "category_path": path,
        "source_url": "https://source.example/" + name,
        "parent_source_url": parent["source_url"] if parent else None,
    }


class Catalog:
    """Zapis może udać się na serwerze, zanim klient dostanie odpowiedź."""

    api_url = "https://test.example/api"

    def __init__(self):
        self.rows = {}
        self.identity = "installation-one"
        self.posts = 0
        self.puts = 0
        self.fail = None

    def get_instance_identity(self):
        return self.identity

    def get(self, resource, resource_id=None, params=None):
        if resource == "languages":
            return {"languages": [{"id": 2}]}
        if resource_id:
            return {"category": copy.deepcopy(self.rows[resource_id])}
        return {"categories": copy.deepcopy(list(self.rows.values()))}

    def save(self, payload, resource_id=None):
        row = {
            key: payload[key] for key in ["name", "id_parent", "link_rewrite", "active"]
        }
        row["name"] = next(iter(row["name"].values()))
        row["link_rewrite"] = next(iter(row["link_rewrite"].values()))
        row["id"] = resource_id or max([9, *self.rows]) + 1
        self.rows[row["id"]] = row
        if self.fail:
            failure, self.fail = self.fail, None
            raise failure
        return {"category": {"id": row["id"]}}

    def post(self, resource, payload):
        self.posts += 1
        return self.save(payload)

    def put(self, resource, resource_id, payload):
        self.puts += 1
        return self.save(payload, resource_id)


def run(tmp_path, client, records):
    path = tmp_path / "categories.json"
    path.write_text(json.dumps(records), encoding="utf-8")
    return import_categories(client, path, tmp_path / "mapping.json")


def test_full_catalog_twice(tmp_path):
    from pathlib import Path

    client = Catalog()
    source = json.loads(
        (
            Path(__file__).resolve().parents[1] / "scraper-results/categories.json"
        ).read_text()
    )
    first, mapping = run(tmp_path, client, source)
    second, again = run(tmp_path, client, source)
    assert first.created == 80 and first.errors == 0
    assert second.created == second.updated == second.errors == 0
    assert second.skipped == 80 and client.posts == 80 and client.puts == 0
    assert mapping == again


def test_post_response_lost_does_not_duplicate(tmp_path):
    client = Catalog()
    client.fail = PrestaShopConnectionError("timeout after commit")
    item = category("A")
    first, _ = run(tmp_path, client, [item])
    assert first.errors == 1 and len(client.rows) == 1
    second, mapping = run(tmp_path, client, [item])
    assert second.errors == 0 and client.posts == 1
    assert mapping[item["source_url"]] == 10


def test_rename_parent_response_lost_preserves_children(tmp_path):
    client = Catalog()
    a = category("A")
    child = category("Child", a)
    run(tmp_path, client, [a, child])
    renamed = {**a, "name": "New", "category_path": ["New"]}
    moved_child = {**child, "category_path": ["New", "Child"]}
    client.fail = PrestaShopConnectionError("lost PUT response")
    stats, _ = run(tmp_path, client, [renamed, moved_child])
    assert stats.errors == 2
    stats, mapping = run(tmp_path, client, [renamed, moved_child])
    assert stats.errors == 0 and client.posts == 2
    assert mapping[a["source_url"]] == 10 and mapping[child["source_url"]] == 11
    assert client.rows[10]["name"] == "New"


def test_installation_changed_same_url_blocks_all_writes(tmp_path):
    client = Catalog()
    item = category("A")
    run(tmp_path, client, [item])
    client.identity = "installation-two"
    with pytest.raises(PrestaShopAPIError, match="innej instalacji"):
        run(tmp_path, client, [item])
    assert client.posts == 1


def test_pending_post_without_server_record_does_not_retry(tmp_path):
    client = Catalog()
    item = category("A")
    client.post = Mock(side_effect=PrestaShopConnectionError("lost before commit"))
    run(tmp_path, client, [item])
    stats, _ = run(tmp_path, client, [item])
    assert stats.errors == 1 and client.post.call_count == 1


def test_ambiguous_siblings_block_only_dependent_branch(tmp_path):
    client = Catalog()
    client.rows = {
        10: {"id": 10, "id_parent": 2, "name": "A", "link_rewrite": "a", "active": 1},
        11: {"id": 11, "id_parent": 2, "name": "a", "link_rewrite": "a", "active": 1},
    }
    a = category("A")
    stats, result = run(
        tmp_path, client, [a, category("Child", a), category("Independent")]
    )
    assert stats.errors == 2 and stats.created == 1
    assert "https://source.example/A" not in result
    assert client.puts == 0


@pytest.mark.parametrize(
    "mutation",
    ["wrong_parent", "missing_parent", "cycle", "same_path", "duplicate_url"],
)
def test_graph_rejects_bad_branch_but_keeps_independent(tmp_path, mutation):
    a = category("A")
    child = category("Child", a)
    independent = category("Independent")
    records = [a, child, independent]
    if mutation == "wrong_parent":
        child["parent_source_url"] = independent["source_url"]
    elif mutation == "missing_parent":
        records.remove(a)
    elif mutation == "cycle":
        a["parent_source_url"] = child["source_url"]
    elif mutation == "same_path":
        records.append({**a, "source_url": "https://source.example/other"})
    else:
        records.append({**a, "name": "B", "category_path": ["B"]})
    path = tmp_path / "source.json"
    path.write_text(json.dumps(records))
    errors = []
    accepted = load_and_validate_categories(path, errors)
    assert independent["source_url"] in {item.source_url for item in accepted}
    assert child["source_url"] not in {item.source_url for item in accepted}
    assert errors


@pytest.mark.parametrize(
    "payload",
    [{"raw_response": "html"}, {"categories": None}, {"categories": [{"id": 10}]}],
)
def test_bad_api_list_prevents_post(tmp_path, payload):
    client = Catalog()
    original = client.get
    client.get = lambda resource, *a, **kw: (
        payload if resource == "categories" else original(resource, *a, **kw)
    )
    with pytest.raises(PrestaShopAPIError):
        run(tmp_path, client, [category("A")])
    assert client.posts == client.puts == 0


def test_missing_languages_blocks_writes(tmp_path):
    client = Catalog()
    client.get = lambda *a, **kw: {}
    with pytest.raises(PrestaShopAPIError):
        run(tmp_path, client, [category("A")])
    assert client.posts == 0


def test_lock_blocks_other_mapping(tmp_path):
    client = Catalog()
    with instance_lock(client.get_instance_identity()):
        with pytest.raises(PrestaShopAPIError, match="już uruchomiony"):
            run(tmp_path, client, [category("A")])
    assert client.posts == 0


def test_mapping_failure_after_api_commit_recovers(tmp_path, monkeypatch):
    import category_import.orchestrator as orchestrator

    client = Catalog()
    item = category("A")
    original = orchestrator.save_category_mapping
    monkeypatch.setattr(
        orchestrator, "save_category_mapping", Mock(side_effect=OSError("disk full"))
    )
    with pytest.raises(OSError):
        run(tmp_path, client, [item])
    monkeypatch.setattr(orchestrator, "save_category_mapping", original)
    stats, _ = run(tmp_path, client, [item])
    assert stats.errors == 0 and client.posts == 1


def test_corrupt_journal_blocks_writes(tmp_path):
    client = Catalog()
    (tmp_path / "mapping.state.json").write_text("{broken")
    with pytest.raises(PrestaShopAPIError, match="dziennika"):
        run(tmp_path, client, [category("A")])
    assert client.posts == 0


def test_unknown_previous_record_not_overwritten(tmp_path):
    client = Catalog()
    item = category("A")
    run(tmp_path, client, [item])
    client.rows[10]["name"] = "Obca edycja"
    stats, _ = run(tmp_path, client, [item])
    assert stats.errors == 1 and client.puts == 0 and client.posts == 1
