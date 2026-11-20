"""Sekwencyjne galerie, trwałe mapowania i uzgadnianie niepewnego POST."""

import hashlib
import io
import json
import time
from collections import Counter
from pathlib import Path

from category_import.prestashop_sync import fetch_active_languages
from import_state import ImportJournal, instance_lock
from PIL import Image, ImageChops, ImageStat
from prestashop_api import PrestaShopAPIError
from product_import.resources import Resources

from .cache import RELEASE_SHA256, RELEASE_URL, ImageCache, digest, source_url
from .planning import read_images


def image_matches(content, path):
    """PS ponownie kompresuje JPEG: porównujemy rozmiar i obraz po dekodowaniu."""
    try:
        with Image.open(io.BytesIO(content)) as actual, Image.open(path) as expected:
            if actual.format != "JPEG" or actual.size != expected.size:
                return False
            left = actual.convert("RGB").resize((64, 64), Image.Resampling.LANCZOS)
            right = expected.convert("RGB").resize((64, 64), Image.Resampling.LANCZOS)
            return max(ImageStat.Stat(ImageChops.difference(left, right)).mean) <= 4
    except (OSError, ValueError, Image.DecompressionBombError):
        return False


def gallery(product):
    associations = product.get("associations")
    if not isinstance(associations, dict) or "images" not in associations:
        raise ValueError("Brak pełnej odpowiedzi galerii.")
    rows = associations["images"]
    if not isinstance(rows, list) or any(
        not isinstance(row, dict) or not str(row.get("id", "")).isdigit()
        for row in rows
    ):
        raise ValueError("Niepoprawna odpowiedź galerii.")
    ids = [int(row["id"]) for row in rows]
    if len(ids) != len(set(ids)) or any(rid <= 0 for rid in ids):
        raise ValueError("Powielone lub niepoprawne ID galerii.")
    return ids


def record_key(sid, url):
    return f"image:{sid}:" + hashlib.sha256(url.encode()).hexdigest()


def legacy_mapping(path, identity, shop_id):
    if path is None:
        return {}
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    if (
        not isinstance(data, dict)
        or data.get("identity") != identity
        or data.get("shop_id") != shop_id
        or not isinstance(data.get("images"), list)
    ):
        raise ValueError("Eksport galerii PHP dotyczy innej instalacji lub sklepu.")
    if (
        not isinstance(data.get("exported_at"), int)
        or not 0 <= time.time() - data["exported_at"] <= 300
    ):
        raise ValueError("Wyeksportuj świeże mapowania galerii PHP (ważność 5 minut).")
    mapping, ids = {}, set()
    for item in data["images"]:
        if (
            not isinstance(item, dict)
            or any(
                not isinstance(item.get(field), int)
                or isinstance(item.get(field), bool)
                or item[field] <= 0
                for field in ("image_id", "product_id")
            )
            or not str(item.get("source_id", "")).isdigit()
        ):
            raise ValueError("Niepoprawny rekord mapowania PHP.")
        source_url(item.get("source_url"))
        key = record_key(str(item["source_id"]), item["source_url"])
        if (
            key in mapping
            or item["image_id"] in ids
            or not isinstance(item["image_id"], int)
            or item["image_id"] <= 0
        ):
            raise ValueError("Konflikt mapowań galerii PHP.")
        for field in ["content_hash", "server_hash"]:
            if (
                not isinstance(item.get(field), str)
                or len(item[field]) != 64
                or any(c not in "0123456789abcdef" for c in item[field])
            ):
                raise ValueError("Niepotwierdzony hash mapowania PHP.")
        mapping[key] = item
        ids.add(item["image_id"])
    return mapping


class GallerySync:
    def __init__(self, store, cache, legacy):
        self.store, self.cache, self.legacy = store, cache, legacy
        self.client, self.journal = store.client, store.journal
        self.counts = Counter()

    def live(self, pid):
        product = self.store.read("products", pid)
        if int(product.get("id_shop_default", 0)) != self.store.context["shop_id"]:
            raise ValueError("Produkt należy do innego sklepu.")
        associations = product.get("associations")
        if isinstance(associations, dict) and "images" not in associations:
            # PS pomija pustą relację także przy display=full. Potwierdzamy
            # pustkę osobnym endpointem, zamiast zgadywać po brakującym polu.
            if self.client.image_ids(pid):
                raise ValueError("API pominęło niepustą galerię produktu.")
            associations["images"] = []
        return product, gallery(product)

    def verify(self, pid, rid, path):
        content = self.client.image_bytes(pid, rid)
        if not image_matches(content, path):
            raise ValueError("Obraz z API nie odpowiada wysyłanej zawartości.")
        return hashlib.sha256(content).hexdigest()

    def run(self, plan, product):
        sid, pid = plan["source_id"], int(product["id"])
        product, live_ids = self.live(pid)
        # Najpierw przygotuj całą galerię; niedostępny plik nie rozpoczyna uploadu.
        paths = {url: self.cache.get(url) for url in plan["urls"]}
        records = {}
        for url in plan["urls"]:
            key = record_key(sid, url)
            record = self.journal.data["records"].get(key)
            if record and (
                record.get("product_id") != pid or record.get("source_url") != url
            ):
                raise ValueError("Konflikt tożsamości mapowania obrazu.")
            if record and record.get("state") == "pending" and record.get("id") is None:
                before = record.get("before_ids")
                if not isinstance(before, list) or not set(before).issubset(live_ids):
                    raise ValueError("Galeria zmieniła się podczas niepewnego POST.")
                added = set(live_ids) - set(before)
                if len(added) != 1 or record["desired"]["content_hash"] != digest(
                    paths[url]
                ):
                    raise ValueError(
                        "Niepewny POST obrazu: wymagane uzgodnienie; bez kolejnego uploadu."
                    )
                rid = added.pop()
                server_hash = self.verify(pid, rid, paths[url])
                record = {
                    **record,
                    "state": "done",
                    "id": rid,
                    "server_hash": server_hash,
                }
                self.journal.put(key, record)
                self.counts["reconciled"] += 1
            if record is None and key in self.legacy:
                previous = self.legacy[key]
                rid = previous["image_id"]
                if previous["product_id"] != pid or rid not in live_ids:
                    raise ValueError("Mapowanie PHP nie odpowiada galerii produktu.")
                content = self.client.image_bytes(pid, rid)
                if hashlib.sha256(content).hexdigest() != previous["server_hash"]:
                    raise ValueError("Obraz PHP zmienił się po eksporcie mapowań.")
                record = {
                    "state": "done",
                    "id": rid,
                    "path": [sid, url],
                    "product_id": pid,
                    "source_url": url,
                    "desired": {"content_hash": previous["content_hash"]},
                    "server_hash": previous["server_hash"],
                    "origin": "php",
                }
                self.journal.put(key, record)
                self.counts["adopted"] += 1
            if record:
                if record.get("id") not in live_ids or record.get("product_id") != pid:
                    raise ValueError(
                        "Zapisany obraz zniknął lub należy do innego produktu."
                    )
                records[url] = record
        known = [records[url]["id"] for url in plan["urls"] if url in records]
        if (
            len(known) != len(set(known))
            or known != live_ids
            or any(url not in records for url in plan["urls"][: len(known)])
        ):
            raise ValueError(
                "Galeria zawiera obce obrazy albo inną kolejność; uzgodnij ją przed importem."
            )
        for url in plan["urls"]:
            key, path = record_key(sid, url), paths[url]
            desired = {"content_hash": digest(path)}
            record = records.get(url)
            _, current_ids = self.live(pid)
            if current_ids != live_ids:
                raise ValueError("Równoległa zmiana galerii; zapis zablokowany.")
            if record:
                content = self.client.image_bytes(pid, record["id"])
                server_hash = hashlib.sha256(content).hexdigest()
                if record["state"] == "done" and server_hash != record.get(
                    "server_hash"
                ):
                    raise ValueError(
                        "Obraz edytowano poza importerem; wymagane uzgodnienie."
                    )
                if record["state"] == "done" and record["desired"] == desired:
                    if not image_matches(content, path):
                        raise ValueError(
                            "Zapisane mapowanie nie odpowiada plikowi źródłowemu."
                        )
                    self.counts["skipped"] += 1
                    continue
            pending = {
                "state": "pending",
                "id": record["id"] if record else None,
                "path": [sid, url],
                "product_id": pid,
                "source_url": url,
                "desired": desired,
                "before_ids": live_ids.copy(),
                "kind": plan["kind"],
                "operation": "update" if record else "create",
            }
            self.journal.put(key, pending)
            response = self.client.upload_image(
                "products", pid, image_path=path, image_id=pending["id"]
            )
            _, after = self.live(pid)
            received = response.get("image", {}).get("id")
            if (
                received is None
                and response.get("binary_response")
                and not record
                and after[:-1] == live_ids
                and len(after) == len(live_ids) + 1
            ):
                received = after[-1]
            if not str(received).isdigit() or int(received) <= 0:
                raise ValueError("Odpowiedź uploadu nie pozwala potwierdzić ID obrazu.")
            rid = int(received)
            if record:
                if rid != record["id"] or after != live_ids:
                    raise ValueError("Aktualizacja zmieniła ID lub galerię.")
            elif rid in live_ids or after != live_ids + [rid]:
                raise ValueError(
                    "POST nie potwierdził jednego nowego obrazu na końcu galerii."
                )
            server_hash = self.verify(pid, rid, path)
            pending.update(state="done", id=rid, server_hash=server_hash)
            self.journal.put(key, pending)
            records[url] = pending
            live_ids = after
            self.counts["updated" if record else "created"] += 1
        expected = [records[url]["id"] for url in plan["urls"]]
        product, after = self.live(pid)
        if after != expected:
            raise ValueError("Końcowa kolejność galerii nie odpowiada źródłu.")
        cover_id = records[plan["cover"]]["id"]
        cover_record = {
            "state": "pending",
            "id": pid,
            "path": [sid, "cover"],
            "desired": {"id_default_image": str(cover_id)},
        }
        cover_key = "image-cover:" + sid
        if int(product.get("id_default_image") or 0) != cover_id:
            self.journal.put(cover_key, cover_record)
            self.client.put(
                "products",
                pid,
                self.store.payload("products", product, cover_record["desired"]),
            )
            product, after = self.live(pid)
            if (
                int(product.get("id_default_image") or 0) != cover_id
                or after != expected
            ):
                raise ValueError("API nie potwierdza okładki produktu.")
            self.counts["covers_updated"] += 1
        cover_record["state"] = "done"
        if self.journal.data["records"].get(cover_key) != cover_record:
            self.journal.put(cover_key, cover_record)
        return {
            "source_id": sid,
            "id": pid,
            "image_ids": expected,
            "cover_id": cover_id,
            "kind": plan["kind"],
            "active": str(product.get("active")) == "1",
        }


def import_images(
    client,
    products_file,
    cache_dir,
    state_file,
    *,
    shop_id=1,
    legacy_map_file=None,
    source_ids=None,
    dry_run=False,
    use_release=True,
):
    started = time.monotonic()
    plans = read_images(products_file)
    if source_ids:
        unknown = set(source_ids) - {p["source_id"] for p in plans}
        if unknown:
            raise ValueError("Nieznane ID produktów: " + ", ".join(sorted(unknown)))
        plans = [p for p in plans if p["source_id"] in source_ids]
    identity = client.get_instance_identity()
    legacy = legacy_mapping(legacy_map_file, identity, shop_id)
    report = {
        "products": [],
        "errors": [],
        "selected": len(plans),
        "dry_run": dry_run,
        "release": {
            "url": RELEASE_URL,
            "version": "product-images-v1",
            "sha256": RELEASE_SHA256,
        },
    }
    with instance_lock(identity):
        journal = ImportJournal(Path(state_file), client.api_url, identity)
        store = Resources(client, journal, {"shop_id": shop_id})
        shops = [
            s
            for s in store.all("shops")
            if int(s["id"]) == shop_id and str(s.get("active")) == "1"
        ]
        if len(shops) != 1:
            raise ValueError("Niepotwierdzony aktywny sklep.")
        store.context["default_language_id"] = int(fetch_active_languages(client)[0])
        cache = ImageCache(cache_dir, use_release=use_release)
        importer = GallerySync(store, cache, legacy)
        store.schema("products")
        for index, plan in enumerate(plans, 1):
            confirmed_before = sum(
                importer.counts[key] for key in ("created", "updated", "skipped")
            )
            try:
                product = store.find("products", {"reference": plan["reference"]})
                if product is None:
                    raise ValueError("Brak produktu z D2.3: " + plan["reference"])
                if int(product.get("id_shop_default", 0)) != shop_id:
                    raise ValueError("Produkt należy do innego sklepu.")
                if dry_run:
                    report["products"].append(
                        {
                            "source_id": plan["source_id"],
                            "id": int(product["id"]),
                            "planned_images": len(plan["urls"]),
                            "kind": plan["kind"],
                        }
                    )
                    continue
                report["products"].append(importer.run(plan, product))
                print(
                    f"[{index}/{len(plans)}] OK {plan['reference']}, images {len(plan['urls'])}",
                    flush=True,
                )
            except (ValueError, OSError, PrestaShopAPIError) as exc:
                confirmed = (
                    sum(
                        importer.counts[key]
                        for key in ("created", "updated", "skipped")
                    )
                    - confirmed_before
                )
                importer.counts["rejected"] += max(0, len(plan["urls"]) - confirmed)
                report["errors"].append(
                    {
                        "stage": "images",
                        "source_id": plan["source_id"],
                        "error": str(exc),
                        "http_status": getattr(exc, "status_code", None),
                    }
                )
                print(
                    f"[{index}/{len(plans)}] ERROR {plan['source_id']}: {exc}",
                    flush=True,
                )
        report["images"] = dict(importer.counts)
        report["cache"] = dict(cache.counts)
        report["release"]["error"] = cache.release_error
    report["duration_seconds"] = round(time.monotonic() - started, 3)
    report["source_images"] = sum(
        len(p["urls"]) for p in plans if p["kind"] == "product"
    )
    report["source_placeholders"] = sum(
        p["kind"] == "source_placeholder" for p in plans
    )
    report["products_with_two_images"] = sum(len(p["urls"]) >= 2 for p in plans)
    report["verified_products"] = len(report["products"]) if not dry_run else 0
    report["verified_images"] = sum(
        len(row.get("image_ids", [])) for row in report["products"]
    )
    report["idempotent"] = (
        not dry_run
        and not report["errors"]
        and not any(
            report["images"].get(key, 0)
            for key in ("created", "updated", "covers_updated")
        )
    )
    return report
