"""Jeden proces D2.5: walidacja → przygotowanie → API → odbiór → aktywacja."""

import hashlib
import json
import os
import platform
import shutil
import subprocess
import time
from dataclasses import asdict

import requests
from category_import import import_categories
from category_import.validator import load_and_validate_categories
from image_import import import_images
from image_import.cache import ImageCache, digest, filename
from image_import.planning import read_images
from import_state import atomic_json, completed_records_guard, instance_lock
from prestashop_api import PrestaShopAPIError, PrestaShopClient
from product_import import import_products
from product_import.planning import read_catalog, stock_plan
from product_import.resources import Resources
from product_import.sync import preflight

from .config import Compose, environment
from .reporting import catalog_summary
from .verify import verify_catalog


def validate_inputs(products_file, categories_file):
    products, errors = read_catalog(products_file)
    if errors or not products:
        raise ValueError(
            "Niepoprawny katalog produktów: " + json.dumps(errors, ensure_ascii=False)
        )
    stock_plan(products)
    errors = []
    categories = load_and_validate_categories(categories_file, validation_errors=errors)
    if errors or not categories:
        raise ValueError("Niepoprawny graf kategorii: " + "; ".join(errors))
    paths = {tuple(c.category_path) for c in categories}
    if any(
        tuple(path[:length]) not in paths
        for p in products
        for path in p["category_paths"]
        for length in range(1, len(path) + 1)
    ):
        raise ValueError("Przypisanie produktu nie występuje w grafie kategorii.")
    images = read_images(products_file)
    return products, categories, images


def snapshot_inputs(directory, products_file, categories_file, fingerprints):
    directory.mkdir(parents=True, exist_ok=True)
    for original, name, key in [
        (products_file, "products.json", "products"),
        (categories_file, "categories.json", "categories"),
    ]:
        content = original.read_bytes()
        if hashlib.sha256(content).hexdigest() != fingerprints[key]:
            raise ValueError(
                "Dane źródłowe zmieniły się podczas przygotowania importu."
            )
        target = directory / name
        if original.resolve() != target.resolve():
            target.write_bytes(content)
    return {"snapshot_confirmed": True}


def read_manifest(path, api_url, fingerprints, sync, pending_paths=()):
    if not path.exists():
        return {
            "version": 1,
            "backend": "api",
            "api_url": api_url,
            "inputs": fingerprints,
            "activation": {},
            "status": "new",
        }
    state = json.loads(path.read_text(encoding="utf-8"))
    if (
        not isinstance(state, dict)
        or state.get("version") != 1
        or state.get("backend") != "api"
        or state.get("api_url") != api_url
        or not isinstance(state.get("activation"), dict)
    ):
        raise ValueError("Stan katalogu ma niepoprawny backend, adres lub format.")
    if state.get("inputs") != fingerprints:
        if not sync:
            raise ValueError(
                "Zmienione dane wymagają --sync; --resume przyjmuje identyczny zestaw."
            )
        paths = pending_paths or [
            path.parent / "products.state.json",
            path.parent / "images.state.json",
            path.parent / "categories.state.json",
        ]
        for journal_path in paths:
            if not journal_path.exists():
                continue
            journal = json.loads(journal_path.read_text(encoding="utf-8"))
            if not isinstance(journal, dict) or not isinstance(
                journal.get("records"), dict
            ):
                raise ValueError(
                    "Niepoprawny dziennik operacji; synchronizacja zatrzymana."
                )
            if any(
                record.get("state") == "pending" and record.get("id") is None
                for record in journal["records"].values()
            ):
                raise ValueError(
                    "Najpierw uzgodnij niepewne POST poprzedniego zestawu przez --resume."
                )
        state["inputs"] = fingerprints
    return state


def activate(client, context, products_report, product_state, manifest, manifest_path):
    state = json.loads(product_state.read_text(encoding="utf-8"))["records"]
    store = Resources(client, None, context)
    count = 0
    for row in products_report["products"]:
        sid, pid = row["source_id"], row["id"]
        record = state["products:pandomu-" + sid]
        if not record.get("owned"):
            continue
        previous = manifest["activation"].get(sid)
        if previous:
            if previous.get("id") != pid:
                raise ValueError("Zmiana ID produktu przy aktywacji: " + sid)
            if previous.get("state") == "done":
                continue  # Zachowaj późniejszą decyzję administratora o dezaktywacji.
        product = store.read("products", pid)
        manifest["activation"][sid] = {"id": pid, "state": "pending"}
        atomic_json(manifest_path, manifest, compact=True)
        if str(product.get("active")) != "1":
            client.put(
                "products", pid, store.payload("products", product, {"active": "1"})
            )
            if str(store.read("products", pid).get("active")) != "1":
                raise ValueError("Niepotwierdzona aktywacja produktu: " + sid)
            count += 1
        manifest["activation"][sid]["state"] = "done"
        atomic_json(manifest_path, manifest, compact=True)
    return count


def export_context(compose, fingerprints):
    context = json.loads(compose.php("export-import-context.php", capture=True))
    if context.get("source_hashes") != fingerprints:
        raise ValueError(
            "Compose ma zamontowane inne dane wejściowe. Uruchom ponownie z --bootstrap."
        )
    return context


def check_dependencies(client, compose, identity, products, fingerprints):
    if client.get_instance_identity() != identity:
        raise ValueError("API wskazuje inną instalację niż wybrany projekt Compose.")
    fresh = export_context(compose, fingerprints)
    preflight(Resources(client, None, fresh), identity, products)
    return {"identity_confirmed": True, "dependencies_confirmed": True}


def run_catalog(args):
    started = time.monotonic()
    values = environment(args.env_file)
    if values.get("REQUESTS_CA_BUNDLE"):
        os.environ["REQUESTS_CA_BUNDLE"] = values["REQUESTS_CA_BUNDLE"]
    if not values.get("PRESTASHOP_API_URL") or not values.get("PRESTASHOP_API_KEY"):
        raise ValueError(
            "Uzupełnij PRESTASHOP_API_URL i PRESTASHOP_API_KEY w wybranym pliku środowiskowym."
        )
    ssl = values.get("PRESTASHOP_API_VERIFY_SSL", "true").lower()
    if ssl not in {"true", "false", "0", "1", "yes", "no"}:
        raise ValueError("Niepoprawna konfiguracja TLS.")
    client = PrestaShopClient(
        values.get("PRESTASHOP_API_URL"),
        values.get("PRESTASHOP_API_KEY"),
        verify_ssl=ssl in {"true", "1", "yes"},
    )
    products, categories, images = validate_inputs(
        args.products_file, args.categories_file
    )
    fingerprints = {
        "products": digest(args.products_file),
        "categories": digest(args.categories_file),
    }
    state_dir = args.state_dir
    manifest_path = state_dir / "catalog-state.json"
    pending_paths = [
        args.product_state_file or state_dir / "products.state.json",
        args.image_state_file or state_dir / "images.state.json",
        (args.mapping_file or state_dir / "categories.json").with_suffix(".state.json"),
    ]
    manifest = read_manifest(
        manifest_path, client.api_url, fingerprints, args.sync, pending_paths
    )
    report = {
        "backend": "api",
        "status": "planned" if args.dry_run else "running",
        "mode": "sync" if args.sync else "resume",
        "reset_stock": args.reset_stock,
        "dry_run": args.dry_run,
        "errors": [],
        "stages": {},
        "summary": catalog_summary({}),
        "planned": {
            "products": len(products),
            "categories": len(categories),
            "combinations": sum(len(p["price_plan"]["variants"]) for p in products),
            "images": sum(len(p["urls"]) for p in images),
        },
        "hardware": {
            "platform": platform.platform(),
            "machine": platform.machine(),
            "cpu_count": os.cpu_count(),
        },
        "inputs": fingerprints,
    }
    if platform.system() == "Darwin":
        report["hardware"]["cpu_model"] = subprocess.run(
            ["sysctl", "-n", "machdep.cpu.brand_string"],
            capture_output=True,
            text=True,
            check=True,
        ).stdout.strip()
        report["hardware"]["memory_bytes"] = int(
            subprocess.run(
                ["sysctl", "-n", "hw.memsize"],
                capture_output=True,
                text=True,
                check=True,
            ).stdout
        )
    if args.dry_run:
        report["duration_seconds"] = round(time.monotonic() - started, 3)
        return report
    mapping = args.mapping_file or state_dir / "categories.json"
    product_state = args.product_state_file or state_dir / "products.state.json"
    image_state = args.image_state_file or state_dir / "images.state.json"
    source_dir = state_dir / "source"
    compose = Compose(args.env_file, values, source_dir)
    current_stage = "cache"
    started_run = False
    owned_run_lock = False

    def stage(name, action):
        nonlocal current_stage
        current_stage = name
        mark = time.monotonic()
        print("[CATALOG] " + name, flush=True)
        result = action()
        report["stages"][name] = {
            "seconds": round(time.monotonic() - mark, 3),
            "result": result,
        }
        report["summary"] = catalog_summary(report["stages"])
        atomic_json(args.report_file or state_dir / "report.json", report)
        return result

    try:
        with instance_lock("catalog-url:" + client.api_url):
            owned_run_lock = True
            manifest = read_manifest(
                manifest_path, client.api_url, fingerprints, args.sync, pending_paths
            )
            cache = ImageCache(args.cache_dir, use_release=not args.no_release)

            def prepare_cache():
                hashes = {
                    url: digest(cache.get(url)) for p in images for url in p["urls"]
                }
                if (
                    manifest.get("images")
                    and hashes != manifest["images"]
                    and not args.sync
                ):
                    raise ValueError("Zmienione pliki zdjęć wymagają --sync.")
                frozen = source_dir / "images"
                frozen.mkdir(parents=True, exist_ok=True)
                for url, checksum in hashes.items():
                    original, target = (
                        args.cache_dir / filename(url),
                        frozen / filename(url),
                    )
                    if original.resolve() != target.resolve() and (
                        not target.exists() or digest(target) != checksum
                    ):
                        shutil.copyfile(original, target)
                    if digest(target) != checksum:
                        raise ValueError(
                            "Cache zmienił się podczas przygotowania zdjęć."
                        )
                manifest["images"] = hashes
                return {
                    "cache": dict(cache.counts),
                    "release_error": cache.release_error,
                }

            stage("cache", prepare_cache)
            stage(
                "snapshot",
                lambda: snapshot_inputs(
                    source_dir, args.products_file, args.categories_file, fingerprints
                ),
            )
            frozen_products, frozen_categories = (
                source_dir / "products.json",
                source_dir / "categories.json",
            )
            if args.bootstrap:
                stage(
                    "compose",
                    lambda: {
                        "ready": bool(
                            compose.run(
                                "up",
                                "-d",
                                "--build",
                                "--wait",
                                "--wait-timeout",
                                "900",
                                "db",
                                "shop",
                                "nginx",
                                "mailpit",
                            )
                            is not None
                        )
                    },
                )
            context = export_context(compose, fingerprints)
            identity = context["identity"]
            if manifest.get("identity") not in {None, identity}:
                raise ValueError(
                    "Stan katalogu dotyczy innej instalacji pod tym adresem."
                )
            authorized_sync = args.sync or manifest.get("sync_in_progress", False)
            protect_completed = (
                manifest.get("verified_once", False) or manifest["status"] == "complete"
            ) and not authorized_sync
            with (
                instance_lock(identity, reentrant=True),
                completed_records_guard(protect_completed),
            ):
                if manifest["status"] == "complete":
                    manifest["verified_once"] = True
                manifest["sync_in_progress"] = authorized_sync
                started_run = True
                manifest.update(identity=identity, status="running")
                atomic_json(manifest_path, manifest, compact=True)
                extra = ["--sync"] if args.sync else []
                stage(
                    "prepare",
                    lambda: {
                        "ready": compose.php("init-shop.php", "--api-prepare", *extra)
                        is not None
                    },
                )

                def import_category_stage():
                    stats, _ = import_categories(client, frozen_categories, mapping)
                    return asdict(stats)

                stage(
                    "dependencies",
                    lambda: check_dependencies(
                        client, compose, identity, products, fingerprints
                    ),
                )
                category_report = stage("categories", import_category_stage)
                if category_report["errors"]:
                    raise ValueError(
                        "Import kategorii: "
                        + "; ".join(category_report["error_details"])
                    )
                context_file = state_dir / "context.json"
                context = export_context(compose, fingerprints)
                atomic_json(context_file, context)
                products_report = stage(
                    "products",
                    lambda: import_products(
                        client,
                        frozen_products,
                        mapping,
                        context_file,
                        product_state,
                        reset_stock=args.reset_stock,
                    ),
                )
                if products_report["errors"]:
                    report["errors"].extend(products_report["errors"])
                    raise ValueError("Nie wszystkie produkty przeszły import.")
                legacy = json.loads(
                    compose.php("export-image-mapping.php", capture=True)
                )
                legacy_path = state_dir / "php-images.json"
                atomic_json(legacy_path, legacy)
                image_report = stage(
                    "images",
                    lambda: import_images(
                        client,
                        frozen_products,
                        source_dir / "images",
                        image_state,
                        shop_id=context["shop_id"],
                        legacy_map_file=legacy_path,
                        use_release=not args.no_release,
                    ),
                )
                if image_report["errors"]:
                    report["errors"].extend(image_report["errors"])
                    raise ValueError("Nie wszystkie galerie przeszły import.")
                # Kontekst z TTL odświeżamy przed niezależną kontrolą, po długich uploadach.
                context = export_context(compose, fingerprints)
                initial = args.reset_stock or (
                    not manifest["activation"]
                    and all(row["seeded"] for row in products_report["products"])
                )
                checks = stage(
                    "verify",
                    lambda: verify_catalog(
                        client,
                        products,
                        json.loads(mapping.read_text(encoding="utf-8")),
                        context,
                        product_state,
                        image_report,
                        initial_stock=initial,
                    ),
                )
                report["checks"] = checks
                manifest["verified_once"] = True
                atomic_json(manifest_path, manifest, compact=True)
                stage(
                    "activation",
                    lambda: {
                        "activated": activate(
                            client,
                            context,
                            products_report,
                            product_state,
                            manifest,
                            manifest_path,
                        )
                    },
                )
                stage(
                    "finalize",
                    lambda: {
                        "ready": compose.php("init-shop.php", "--api-finalize", *extra)
                        is not None
                    },
                )
                context = export_context(compose, fingerprints)
                report["checks"] = stage(
                    "final_check",
                    lambda: verify_catalog(
                        client,
                        products,
                        json.loads(mapping.read_text(encoding="utf-8")),
                        context,
                        product_state,
                        image_report,
                    ),
                )
                # Sprawdzenie strony po finalizacji nie korzysta z klucza API.
                response = requests.get(
                    client.api_url.rsplit("/api", 1)[0] + "/",
                    timeout=30,
                    verify=client.verify_ssl,
                    allow_redirects=False,
                )
                if response.status_code != 200:
                    raise ValueError(
                        "Końcowa kontrola strony sklepu: HTTP "
                        + str(response.status_code)
                    )
                report["checks"]["storefront_http"] = response.status_code
                report["status"] = "complete"
                manifest["verified_once"] = True
                manifest["sync_in_progress"] = False
                manifest["status"] = "complete"
                atomic_json(manifest_path, manifest, compact=True)
    except (
        ValueError,
        OSError,
        PrestaShopAPIError,
        KeyError,
        TypeError,
        requests.RequestException,
        KeyboardInterrupt,
    ) as exc:
        report["status"] = "failed"
        report["interrupted"] = isinstance(exc, KeyboardInterrupt)
        report["errors"].append(
            {
                "stage": current_stage,
                "error": "Import przerwany przez użytkownika."
                if isinstance(exc, KeyboardInterrupt)
                else str(exc),
                "http_status": getattr(exc, "status_code", None),
            }
        )
        if started_run:
            manifest["status"] = "failed"
            atomic_json(manifest_path, manifest, compact=True)
    finally:
        report["summary"] = catalog_summary(report["stages"])
        report["metrics"] = client.metrics
        report["duration_seconds"] = round(time.monotonic() - started, 3)
        report["compose_seconds"] = (
            report["stages"].get("compose", {}).get("seconds", 0)
        )
        report["import_seconds"] = round(
            report["duration_seconds"] - report["compose_seconds"], 3
        )
        report["demo_weights"] = sum(p["weight_origin"] == "demo" for p in products)
        report["source_placeholders"] = sum(
            p["kind"] == "source_placeholder" for p in images
        )
        if report.get("checks"):
            pstats = report["stages"]["products"]["result"]["resources"]
            report["idempotent"] = (
                not report["errors"]
                and not any(
                    c.get("created", 0) or c.get("updated", 0) for c in pstats.values()
                )
                and report["stages"]["images"]["result"]["idempotent"]
                and report["stages"]["activation"]["result"]["activated"] == 0
                and report["stages"]["categories"]["result"]["created"] == 0
                and report["stages"]["categories"]["result"]["updated"] == 0
                and not any(
                    client.metrics["methods"].get(method, 0)
                    for method in ("POST", "PUT", "DELETE")
                )
            )
        if owned_run_lock:
            atomic_json(args.report_file or state_dir / "report.json", report)
    return report
