"""Testy D2.4 bez sieci: cache, ZIP, mapowania i niepewne uploady."""

import copy
import io
import json
import stat
import zipfile
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock

import pytest
from bs4 import BeautifulSoup
from image_import.cache import ImageCache, extract_archive, filename, jpeg_valid
from image_import.planning import PLACEHOLDER, read_images
from image_import.sync import GallerySync, record_key
from import_state import ImportJournal
from PIL import Image
from prestashop_api import PrestaShopConnectionError
from scraper_elements.scrap_images import scrap_source_placeholder

ROOT = Path(__file__).resolve().parents[1]
URL = "https://pandomu.com.pl/123-large_default/item.jpg"


def picture(color="red", format="JPEG", mode="RGB", size=(1000, 800)):
    stream = io.BytesIO()
    Image.new(mode, size, color).save(stream, format=format)
    return stream.getvalue()


def test_real_gallery_counts_and_placeholder():
    plans = read_images(ROOT / "scraper-results/products.json")
    assert len(plans) == 574
    assert sum(len(p["urls"]) for p in plans) == 1389
    assert sum(p["kind"] == "source_placeholder" for p in plans) == 1
    assert sum(len(p["urls"]) >= 2 for p in plans) == 436
    assert next(p for p in plans if p["source_id"] == "539")["urls"] == [PLACEHOLDER]


def test_placeholder_is_read_from_source_metadata():
    soup = BeautifulSoup(
        '<div class="product-cover"><img src="' + PLACEHOLDER + '"></div>',
        "html.parser",
    )
    assert scrap_source_placeholder(soup) == PLACEHOLDER
    assert (
        scrap_source_placeholder(
            BeautifulSoup('<img src="https://evil.test/pl-default.jpg">', "html.parser")
        )
        is None
    )


def test_cache_name_matches_existing_scraper():
    assert filename(URL) == "123-large_default_item.jpg"
    assert filename(PLACEHOLDER) == "p_pl-default-medium_default.jpg"


@pytest.mark.parametrize(
    "path",
    [
        "../escape.jpg",
        "/escape.jpg",
        "images/../../escape.jpg",
        "C:/escape.jpg",
        "images\\escape.jpg",
    ],
)
def test_unsafe_zip_never_writes_outside_cache(tmp_path, path):
    archive = tmp_path / "source.zip"
    with zipfile.ZipFile(archive, "w") as bundle:
        bundle.writestr(path, picture())
    with pytest.raises(ValueError, match="Niebezpieczna"):
        extract_archive(archive, tmp_path / "cache")
    assert not (tmp_path / "cache").exists()


def test_zip_symlink_and_duplicate_names_block_extraction(tmp_path):
    archive = tmp_path / "source.zip"
    info = zipfile.ZipInfo("link.jpg")
    info.external_attr = (stat.S_IFLNK | 0o777) << 16
    with zipfile.ZipFile(archive, "w") as bundle:
        bundle.writestr(info, "outside")
    with pytest.raises(ValueError):
        extract_archive(archive, tmp_path / "cache")
    with zipfile.ZipFile(archive, "w") as bundle:
        bundle.writestr("a/x.jpg", picture())
        bundle.writestr("b/x.jpg", picture())
    with pytest.raises(ValueError, match="Powielona"):
        extract_archive(archive, tmp_path / "cache")


def test_zip_limits_are_checked_before_extraction(tmp_path, monkeypatch):
    import image_import.cache as module

    archive = tmp_path / "source.zip"
    with zipfile.ZipFile(archive, "w") as bundle:
        bundle.writestr("x.jpg", picture())
    monkeypatch.setattr(module, "MAX_EXPANDED", 1)
    with pytest.raises(ValueError, match="limity"):
        extract_archive(archive, tmp_path / "cache")


def test_valid_cache_is_not_recompressed_or_downloaded(tmp_path):
    target = tmp_path / filename(URL)
    content = picture()
    target.write_bytes(content)
    cache = ImageCache(tmp_path, session=Mock())
    assert cache.get(URL) == target and target.read_bytes() == content
    cache.session.get.assert_not_called()


def test_webp_alpha_exif_and_dimensions_are_normalized(tmp_path):
    target = tmp_path / filename(URL)
    target.write_bytes(picture((255, 0, 0, 0), "WEBP", "RGBA", (200, 100)))
    cache = ImageCache(tmp_path, session=Mock())
    assert cache.get(URL) == target and jpeg_valid(target)
    with Image.open(target) as image:
        assert max(image.size) == 800 and image.mode == "RGB"
        assert min(image.getpixel((0, 0))) > 245
    cache.session.get.assert_not_called()


def test_bad_cache_release_failure_then_source_fallback(tmp_path):
    target = tmp_path / filename(URL)
    target.write_bytes(b"bad")
    cache = ImageCache(tmp_path)

    def download(url, destination, limit):
        destination.write_bytes(b"bad zip" if url.endswith(".zip") else picture())

    cache.download = Mock(side_effect=download)
    assert jpeg_valid(cache.get(URL))
    assert cache.release_error and cache.counts["source_downloads"] == 1
    assert cache.counts["release_downloads"] == 1
    cache.get(URL)
    assert cache.counts["release_downloads"] == 1


def test_zip_valid_members_keep_bytes_and_bad_member_requires_fallback(tmp_path):
    archive = tmp_path / "source.zip"
    content = picture()
    with zipfile.ZipFile(archive, "w") as bundle:
        bundle.writestr("images/valid.jpg", content)
        bundle.writestr("images/bad.jpg", b"bad")
    assert extract_archive(archive, tmp_path / "cache") == 1
    assert (tmp_path / "cache/valid.jpg").read_bytes() == content
    assert not (tmp_path / "cache/bad.jpg").exists()


class ImagesAPI:
    api_url = "https://shop/api"

    def __init__(self):
        self.ids, self.content, self.posts, self.updates = [], {}, 0, 0
        self.cover, self.lose, self.commit = 0, False, True

    def product(self):
        return {
            "id": "1",
            "id_shop_default": "1",
            "active": "0",
            "id_default_image": str(self.cover),
            "associations": {"images": [{"id": str(rid)} for rid in self.ids]},
        }

    def upload_image(self, resource, pid, image_path, image_id=None):
        if image_id is None:
            self.posts += 1
            rid = max(self.ids, default=0) + 1
        else:
            self.updates += 1
            rid = image_id
        if self.commit:
            if rid not in self.ids:
                self.ids.append(rid)
            self.content[rid] = image_path.read_bytes()
        if self.lose:
            self.lose = False
            raise PrestaShopConnectionError("lost")
        return {"image": {"id": str(rid)}}

    def image_bytes(self, pid, rid):
        return self.content[rid]

    def put(self, resource, pid, desired):
        self.cover = int(desired["id_default_image"])


def importer(tmp_path, api):
    path = tmp_path / filename(URL)
    if not path.exists():
        path.write_bytes(picture())
    cache = SimpleNamespace(get=lambda url: path)
    journal = ImportJournal(tmp_path / "state.json", api.api_url, "fixture")
    store = SimpleNamespace(
        client=api,
        journal=journal,
        context={"shop_id": 1},
        read=lambda *args: copy.deepcopy(api.product()),
        payload=lambda resource, product, desired: desired,
    )
    return GallerySync(store, cache, {}), path


def plan():
    return {"source_id": "5", "urls": [URL], "cover": URL, "kind": "product"}


def test_repeated_gallery_skips_and_preserves_inactivity(tmp_path):
    api = ImagesAPI()
    sync, _ = importer(tmp_path, api)
    assert sync.run(plan(), api.product())["active"] is False
    again, _ = importer(tmp_path, api)
    again.run(plan(), api.product())
    assert api.posts == 1 and api.updates == 0 and api.cover == 1
    assert again.counts["skipped"] == 1


def test_lost_post_is_reconciled_without_second_upload(tmp_path):
    api = ImagesAPI()
    api.lose = True
    sync, _ = importer(tmp_path, api)
    with pytest.raises(PrestaShopConnectionError):
        sync.run(plan(), api.product())
    again, _ = importer(tmp_path, api)
    result = again.run(plan(), api.product())
    assert api.posts == 1 and result["image_ids"] == [1]
    assert again.counts["reconciled"] == 1


@pytest.mark.parametrize("extra", [False, True])
def test_uncertain_post_with_zero_or_multiple_new_ids_never_reposts(tmp_path, extra):
    api = ImagesAPI()
    api.lose, api.commit = True, extra
    sync, path = importer(tmp_path, api)
    with pytest.raises(PrestaShopConnectionError):
        sync.run(plan(), api.product())
    if extra:
        api.ids.append(2)
        api.content[2] = path.read_bytes()
    again, _ = importer(tmp_path, api)
    with pytest.raises(ValueError, match="Niepewny POST"):
        again.run(plan(), api.product())
    assert api.posts == 1


def test_changed_content_and_lost_put_keep_image_id(tmp_path):
    api = ImagesAPI()
    sync, path = importer(tmp_path, api)
    sync.run(plan(), api.product())
    path.write_bytes(picture("blue"))
    api.lose = True
    with pytest.raises(PrestaShopConnectionError):
        sync.run(plan(), api.product())
    again, _ = importer(tmp_path, api)
    again.run(plan(), api.product())
    assert api.ids == [1] and api.posts == 1 and api.updates == 2


def test_unmapped_gallery_and_external_edit_are_blocked(tmp_path):
    api = ImagesAPI()
    sync, _ = importer(tmp_path, api)
    api.ids = [99]
    with pytest.raises(ValueError, match="obce obrazy"):
        sync.run(plan(), api.product())
    assert api.posts == 0
    api.ids = []
    sync.run(plan(), api.product())
    api.content[1] = picture("green")
    with pytest.raises(ValueError, match="poza importerem"):
        sync.run(plan(), api.product())
    assert api.updates == 0


def test_existing_php_mapping_is_adopted_without_post(tmp_path):
    import hashlib

    api = ImagesAPI()
    sync, path = importer(tmp_path, api)
    api.ids, api.content = [7], {7: path.read_bytes()}
    sha = hashlib.sha256(path.read_bytes()).hexdigest()
    sync.legacy = {
        record_key("5", URL): {
            "product_id": 1,
            "image_id": 7,
            "server_hash": sha,
            "content_hash": sha,
        }
    }
    sync.run(plan(), api.product())
    assert api.posts == api.updates == 0 and sync.counts["adopted"] == 1


def test_exif_orientation_is_applied_then_removed(tmp_path):
    content = io.BytesIO()
    exif = Image.Exif()
    exif[274] = 6
    Image.new("RGB", (1000, 800), "red").save(content, format="JPEG", exif=exif)
    target = tmp_path / filename(URL)
    target.write_bytes(content.getvalue())
    cache = ImageCache(tmp_path, session=Mock())
    cache.get(URL)
    with Image.open(target) as image:
        assert image.size == (800, 1000)
        assert image.getexif().get(274, 1) == 1


def test_webp_zip_member_is_converted_to_cache_jpeg(tmp_path):
    archive = tmp_path / "source.zip"
    with zipfile.ZipFile(archive, "w") as bundle:
        bundle.writestr("images/converted.webp", picture(format="WEBP"))
    assert extract_archive(archive, tmp_path / "cache") == 1
    assert jpeg_valid(tmp_path / "cache/converted.jpg")


def test_streaming_download_checks_limit_without_leaving_partial_file(tmp_path):
    cache = ImageCache(tmp_path)
    response = Mock(headers={})
    response.iter_content.return_value = [b"123", b"456"]
    cache.session.get = Mock(
        return_value=Mock(
            __enter__=Mock(return_value=response), __exit__=Mock(return_value=False)
        )
    )
    target = tmp_path / "source"
    with pytest.raises(ValueError, match="limit pobierania"):
        cache.download(URL, target, 5)
    assert not target.exists() and list(tmp_path.iterdir()) == []


def test_invalid_gallery_shape_fails_before_any_writes(tmp_path):
    file = tmp_path / "products.json"
    file.write_text(json.dumps([{"product_id": "5", "images": [{}]}]))
    with pytest.raises(ValueError, match="galeria"):
        read_images(file)


def test_wrong_product_id_in_saved_mapping_is_a_conflict(tmp_path):
    api = ImagesAPI()
    sync, _ = importer(tmp_path, api)
    sync.run(plan(), api.product())
    key = record_key("5", URL)
    sync.journal.data["records"][key]["product_id"] = 22
    with pytest.raises(ValueError, match="tożsamości"):
        sync.run(plan(), api.product())
    assert api.posts == 1


@pytest.mark.parametrize(
    "product",
    [
        {},
        {"associations": {}},
        {"associations": {"images": {}}},
        {"associations": {"images": [{"id": "bad"}]}},
    ],
)
def test_malformed_gallery_is_not_an_empty_catalog(product):
    from image_import.sync import gallery

    with pytest.raises(ValueError):
        gallery(product)
