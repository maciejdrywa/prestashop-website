"""D2.4: rzeczywiste zapisy wyłącznie na odrębnej instancji Compose D2."""

import json
from unittest.mock import Mock

import pytest
from image_import import import_images
from image_import.cache import filename
from PIL import Image
from prestashop_api import PrestaShopConnectionError
from test_api_integration import client as shared_client
from test_product_import_integration import batch as shared_batch

client = shared_client
batch = shared_batch
pytestmark = pytest.mark.integration


@pytest.fixture
def gallery_batch(batch, client, tmp_path):
    rows, _, create = batch
    report = create()
    assert not report["errors"]
    urls = [
        "https://pandomu.com.pl/999901-large_default/d24-first.jpg",
        "https://pandomu.com.pl/999902-large_default/d24-second.jpg",
    ]
    source = [
        {"product_id": rows[0]["product_id"], "images": urls, "cover_image": urls[1]}
    ]
    file = tmp_path / "gallery.json"
    file.write_text(json.dumps(source))
    cache = tmp_path / "cache"
    cache.mkdir()
    for url, color in zip(urls, ["red", "blue"]):
        Image.new("RGB", (900, 800), color).save(cache / filename(url), "JPEG")

    def run(**kwargs):
        return import_images(
            client,
            file,
            cache,
            tmp_path / "images.state.json",
            use_release=False,
            **kwargs,
        )

    return source, file, cache, run, tmp_path


def test_real_upload_cover_content_update_and_idempotence(gallery_batch, client):
    source, file, cache, run, _ = gallery_batch
    first = run()
    assert not first["errors"] and first["images"]["created"] == 2
    product = first["products"][0]
    assert product["cover_id"] == product["image_ids"][1] and not product["active"]
    second = run()
    assert not second["errors"] and second["images"] == {"skipped": 2}
    assert second["products"] == first["products"]
    Image.new("RGB", (900, 800), "green").save(
        cache / filename(source[0]["images"][0]), "JPEG"
    )
    updated = run()
    assert not updated["errors"] and updated["images"]["updated"] == 1
    assert updated["products"][0]["image_ids"] == product["image_ids"]
    source[0]["cover_image"] = source[0]["images"][0]
    file.write_text(json.dumps(source))
    changed = run()
    assert not changed["errors"] and changed["images"]["covers_updated"] == 1
    assert changed["products"][0]["cover_id"] == product["image_ids"][0]
    saved = client.get("products", product["id"], params={"display": "full"})[
        "products"
    ][0]
    assert saved["active"] == "0"


def test_lost_post_response_reconciles_without_duplicate(gallery_batch, client):
    _, _, _, run, _ = gallery_batch
    original = client.upload_image
    count = 0

    def lose(*args, **kwargs):
        nonlocal count
        result = original(*args, **kwargs)
        count += 1
        if count == 1:
            raise PrestaShopConnectionError("Injected loss after commit")
        return result

    client.upload_image = lose
    first = run()
    assert len(first["errors"]) == 1
    resumed = run()
    assert not resumed["errors"] and resumed["images"]["reconciled"] == 1
    assert len(resumed["products"][0]["image_ids"]) == 2 and count == 2


def test_lost_update_response_keeps_id_and_gallery(gallery_batch, client):
    source, _, cache, run, _ = gallery_batch
    first = run()
    assert not first["errors"]
    Image.new("RGB", (900, 800), "green").save(
        cache / filename(source[0]["images"][0]), "JPEG"
    )
    original = client.upload_image
    lost = False

    def lose(*args, **kwargs):
        nonlocal lost
        result = original(*args, **kwargs)
        if kwargs.get("image_id") and not lost:
            lost = True
            raise PrestaShopConnectionError("Injected loss after update")
        return result

    client.upload_image = lose
    assert run()["errors"]
    resumed = run()
    assert not resumed["errors"]
    assert resumed["products"][0]["image_ids"] == first["products"][0]["image_ids"]


def test_dry_run_does_not_download_or_write(gallery_batch, client, monkeypatch):
    _, _, _, run, tmp_path = gallery_batch
    monkeypatch.setattr(
        "image_import.cache.ImageCache.get",
        Mock(side_effect=AssertionError("download")),
    )
    monkeypatch.setattr(
        client, "upload_image", Mock(side_effect=AssertionError("upload"))
    )
    monkeypatch.setattr(client, "put", Mock(side_effect=AssertionError("write")))
    planned = run(dry_run=True)
    assert not planned["errors"] and len(planned["products"]) == 1
    assert not (tmp_path / "images.state.json").exists()


def test_adopt_php_gallery_map_without_reupload(gallery_batch, client, monkeypatch):
    import base64
    import subprocess

    from image_import.cache import digest

    source, _, cache, run, tmp_path = gallery_batch
    first = run()
    assert not first["errors"]
    product = first["products"][0]
    items = [
        {
            "source_id": source[0]["product_id"],
            "url": url,
            "image_id": rid,
            "hash": digest(cache / filename(url)),
        }
        for url, rid in zip(source[0]["images"], product["image_ids"])
    ]
    encoded = base64.b64encode(json.dumps(items).encode()).decode()
    code = (
        'require "/opt/shop/init/lib/images.php"; ShopInit\\boot(); ShopInit\\ensureImageMap(); '
        f'$rows=json_decode(base64_decode("{encoded}"),true); '
        "foreach($rows as $row){ if(!Db::getInstance()->insert(ShopInit\\IMAGE_MAP_TABLE,["
        '"id_shop"=>1,"source_product_id"=>$row["source_id"],"source_url_hash"=>hash("sha256",$row["url"]),'
        '"source_url"=>$row["url"],"id_image"=>$row["image_id"],"content_hash"=>$row["hash"]])) throw new RuntimeException("fixture map"); }'
    )
    command = [
        "docker",
        "exec",
        "--user",
        "www-data",
        "prestashop-d21-tests-shop-1",
        "php",
    ]
    try:
        subprocess.run(
            command + ["-r", code], check=True, capture_output=True, text=True
        )
        exported = subprocess.run(
            command + ["/opt/shop/init/export-image-mapping.php"],
            check=True,
            capture_output=True,
            text=True,
        ).stdout
        mapping = tmp_path / "php-map.json"
        mapping.write_text(exported)
        (tmp_path / "images.state.json").unlink()
        monkeypatch.setattr(
            client, "upload_image", Mock(side_effect=AssertionError("duplicate upload"))
        )
        adopted = run(legacy_map_file=mapping)
        assert not adopted["errors"] and adopted["images"]["adopted"] == 2
        assert adopted["products"][0]["image_ids"] == product["image_ids"]
        assert adopted["images"].get("created", 0) == 0
    finally:
        sid = source[0]["product_id"]
        cleanup = (
            'require "/opt/shop/init/lib/images.php"; ShopInit\\boot(); '
            f"""Db::getInstance()->delete(ShopInit\\IMAGE_MAP_TABLE, "source_product_id = '{sid}'");"""
        )
        subprocess.run(
            command + ["-r", cleanup], check=True, capture_output=True, text=True
        )
