"""Import D2.3 przez Webservice: produkty, słowniki, kombinacje i StockAvailable."""

from __future__ import annotations

import json
import time
from pathlib import Path

from category_import.mapping import load_category_mapping
from category_import.prestashop_sync import fetch_active_languages
from import_state import ImportJournal, atomic_json, instance_lock
from prestashop_api import PrestaShopAPIError

from .planning import distribute_stock, number, product_slug, read_catalog, stock_plan
from .resources import Resources


def preflight(store, identity, products):
    context = store.context
    if not isinstance(context, dict) or not isinstance(context.get("shop_id"), int):
        raise ValueError("Niepoprawny format kontekstu sklepu.")
    exported_at = context.get("exported_at")
    if not isinstance(exported_at, int) or not 0 <= time.time() - exported_at <= 300:
        raise ValueError("Wyeksportuj świeży kontekst sklepu (ważność: 5 minut).")
    if (
        context.get("identity") != identity
        or context.get("currency") != "PLN"
        or context.get("country") != "PL"
        or context.get("weight_unit", "").lower() != "kg"
        or context.get("tax_enabled") is not True
        or context.get("stock_enabled") is not True
    ):
        raise ValueError(
            "Kontekst wymaga tej samej instalacji, Polski, PLN, kg oraz włączonych podatków i magazynu."
        )
    shops = [
        shop
        for shop in store.all("shops")
        if int(shop["id"]) == context["shop_id"] and str(shop.get("active")) == "1"
    ]
    if len(shops) != 1 or int(shops[0]["id_category"]) != context.get(
        "home_category_id"
    ):
        raise ValueError("Niepotwierdzony sklep lub korzeń kategorii.")
    if context.get("home_category_id") != 2:
        raise ValueError("Importer kategorii D2.2 wymaga korzenia kategorii 2.")
    langs = fetch_active_languages(store.client)
    if str(context.get("default_language_id")) not in langs:
        raise ValueError("Brak domyślnego języka w aktywnych językach API.")
    currencies = [
        row
        for row in store.all("currencies")
        if row.get("iso_code") == "PLN"
        and str(row.get("active")) == "1"
        and str(row.get("deleted")) == "0"
    ]
    countries = [
        row
        for row in store.all("countries")
        if row.get("iso_code") == "PL" and str(row.get("active")) == "1"
    ]
    if not currencies or len(countries) != 1:
        raise ValueError("API nie potwierdza aktywnych PLN i Polski.")
    taxes = {int(row["id"]): row for row in store.all("taxes")}
    rules = store.all("tax_rules")
    tax_groups = {}
    for rate in {p["price_plan"]["vat"] for p in products}:
        matches = []
        for group in store.all("tax_rule_groups"):
            gid = int(group["id"])
            if (
                gid not in context.get("tax_group_ids", [])
                or str(group.get("active")) != "1"
                or str(group.get("deleted")) != "0"
            ):
                continue
            relevant = [
                r
                for r in rules
                if int(r["id_tax_rules_group"]) == gid
                and int(r["id_country"]) == int(countries[0]["id"])
            ]
            if len(relevant) != 1:
                continue
            rule = relevant[0]
            tax = taxes.get(int(rule["id_tax"]), {})
            if (
                all(
                    str(rule.get(field)) == "0"
                    for field in ["id_state", "zipcode_from", "zipcode_to", "behavior"]
                )
                and str(tax.get("active")) == "1"
                and str(tax.get("deleted")) == "0"
                and number(tax.get("rate")) == number(rate)
            ):
                matches.append(gid)
        if not matches:
            raise ValueError(f"Brak jednoznacznej ogólnej polskiej reguły VAT {rate}%.")
        # Jak PHP: kilka równoważnych grup rozstrzyga najniższe ID.
        tax_groups[rate] = min(matches)
    for resource in [
        "products",
        "combinations",
        "product_options",
        "product_option_values",
        "product_features",
        "product_feature_values",
        "stock_availables",
    ]:
        store.schema(resource)
    return langs, tax_groups


def category_ids(source, mapping, live_categories):
    ids = set()
    for path in source["category_paths"]:
        for length in range(1, len(path) + 1):
            key = " > ".join(path[:length])
            rid = mapping.get(key)
            row = live_categories.get(rid)
            parent = 2 if length == 1 else mapping.get(" > ".join(path[: length - 1]))
            if (
                row is None
                or int(row["id_parent"]) != parent
                or str(row.get("active")) != "1"
            ):
                raise ValueError(
                    f"Brak poprawnego mapowania kategorii: {key}. Uruchom import kategorii."
                )
            ids.add(rid)
    return sorted(ids), mapping[" > ".join(source["primary"])]


def translated(value, languages):
    return {lid: value for lid in languages}


def feature_ids(store, features, languages):
    result = []
    for feature in features:
        name, value = feature["name"], feature["value"]
        group, _ = store.sync(
            "product_features",
            name,
            {"name": translated(name, languages)},
            {"name": translated(name, languages)},
        )
        gid = int(group["id"])
        item, _ = store.sync(
            "product_feature_values",
            f"{gid}:{value}",
            {"id_feature": str(gid), "value": translated(value, languages)},
            {
                "id_feature": str(gid),
                "custom": "0",
                "value": translated(value, languages),
            },
        )
        result.append({"id": str(gid), "id_feature_value": str(item["id"])})
    return result


def option_ids(store, attributes, languages):
    result = []
    for name, value in attributes.items():
        group, _ = store.sync(
            "product_options",
            name,
            {"name": translated(name, languages)},
            {
                "name": translated(name, languages),
                "public_name": translated(name, languages),
            },
            new_defaults={"group_type": "select", "is_color_group": "0"},
        )
        gid = int(group["id"])
        item, _ = store.sync(
            "product_option_values",
            f"{gid}:{value}",
            {"id_attribute_group": str(gid), "name": translated(value, languages)},
            {"id_attribute_group": str(gid), "name": translated(value, languages)},
        )
        result.append({"id": str(item["id"])})
    return result


def inventory(store, pid, expected_combinations):
    rows = store.many("stock_availables", {"filter[id_product]": f"[{pid}]"})
    by_variant = {}
    for row in rows:
        vid = int(row["id_product_attribute"])
        if (
            int(row.get("id_shop", 0)) != store.context["shop_id"]
            or int(row.get("id_shop_group", 0)) != 0
            or str(row["depends_on_stock"]) != "0"
            or vid in by_variant
        ):
            raise ValueError(
                "Niejednoznaczny, współdzielony lub zaawansowany magazyn; zapis zablokowany."
            )
        by_variant[vid] = row
    if set(by_variant) != {0, *expected_combinations}:
        raise ValueError(f"Niepełne wiersze magazynu produktu {pid}.")
    return by_variant


def seed_stock(store, source, product, owned, combinations, total, reset_stock):
    pid = int(product["id"])
    planned = (
        distribute_stock(
            total, [v["source_id"] for v in source["price_plan"]["variants"]]
        )
        if combinations
        else {}
    )
    quantities = {combinations[sid]: quantity for sid, quantity in planned.items()}
    rows = inventory(store, pid, set(combinations.values()))
    key = "stock-seed:" + source["product_id"]
    previous = store.journal.data["records"].get(key)
    seed = reset_stock or (
        owned
        and str(product["active"]) == "0"
        and (not previous or previous["state"] != "done")
    )
    if seed:
        record = {
            "state": "pending",
            "id": pid,
            "path": ["stock", source["product_id"]],
            "desired": {
                "quantities": {str(k): v for k, v in quantities.items()},
                "total": total,
            },
        }
        store.journal.put(key, record)
        targets = {**quantities, 0: total}
        for vid, quantity in targets.items():
            row = rows[vid]
            desired = {
                "quantity": str(quantity),
                "out_of_stock": "0",
                "depends_on_stock": "0",
            }
            if not store.matches("stock_availables", row, desired):
                store.client.put(
                    "stock_availables",
                    int(row["id"]),
                    store.payload("stock_availables", row, desired),
                )
        record["state"] = "done"
        # Weryfikacja poprzedza checkpoint; nieaktywne nowe produkty można bezpiecznie wznowić.
        rows = inventory(store, pid, set(combinations.values()))
        if any(
            int(rows[vid]["quantity"]) != quantity
            or str(rows[vid]["out_of_stock"]) != "0"
            for vid, quantity in targets.items()
        ):
            raise ValueError("API nie potwierdza początkowego stanu magazynu.")
        store.journal.put(key, record)
    default_updated = False
    if combinations:
        if int(rows[0]["quantity"]) != sum(
            int(rows[vid]["quantity"]) for vid in combinations.values()
        ):
            raise ValueError("Suma stanów kombinacji nie odpowiada stanowi produktu.")
        current = store.read("products", pid)
        candidates = [
            vid for vid in combinations.values() if int(rows[vid]["quantity"]) > 0
        ] or list(combinations.values())
        default = int(current.get("id_default_combination") or 0)
        chosen = default if default in candidates else candidates[0]
        # Getter id_default_combination może zwrócić pierwszy wariant nawet bez default_on.
        # Natywny kalkulator ceny bazowej wymaga rzeczywiście zapisanego default_on=1.
        saved = store.many("combinations", {"filter[id_product]": f"[{pid}]"})
        flagged = [int(row["id"]) for row in saved if str(row.get("default_on")) == "1"]
        if (
            flagged != [chosen]
            or int(current.get("cache_default_attribute") or 0) != chosen
        ):
            store.client.put(
                "products",
                pid,
                store.payload(
                    "products", current, {"id_default_combination": str(chosen)}
                ),
            )
            default_updated = True
            saved = store.many("combinations", {"filter[id_product]": f"[{pid}]"})
            if [
                int(row["id"]) for row in saved if str(row.get("default_on")) == "1"
            ] != [chosen]:
                raise ValueError(
                    "API nie potwierdza rzeczywistego wariantu domyślnego."
                )
    return {
        "seeded": seed,
        "quantity": int(rows[0]["quantity"]),
        "default_updated": default_updated,
    }


def import_products(
    client,
    products_file: Path,
    mapping_file: Path,
    context_file: Path,
    state_file: Path,
    *,
    source_ids=None,
    reset_stock=False,
    dry_run=False,
):
    started = time.monotonic()
    products, errors = read_catalog(products_file)
    if not products:
        raise ValueError("Brak poprawnych produktów do importu.")
    stocks = stock_plan(products)
    selected = products
    if source_ids:
        unknown = set(source_ids) - {p["product_id"] for p in products}
        if unknown:
            raise ValueError(
                "Brak poprawnych produktów o ID: " + ", ".join(sorted(unknown))
            )
        selected = [p for p in products if p["product_id"] in source_ids]
    identity = client.get_instance_identity()
    context = json.loads(context_file.read_text(encoding="utf-8"))
    mapping = load_category_mapping(
        mapping_file, expected_instance_url=client.api_url, expected_identity=identity
    )
    if not mapping:
        raise ValueError("Brak zweryfikowanej mapy kategorii D2.2.")
    report = {
        "total": len(products) + len(errors),
        "selected": len(selected),
        "errors": errors,
        "products": [],
        "resources": {},
        "demo_weights": sum(p["weight_origin"] == "demo" for p in selected),
        "price_adjustments": sum(len(p["price_plan"]["adjustments"]) for p in selected),
        "dry_run": dry_run,
    }
    with instance_lock(identity):
        journal = ImportJournal(state_file, client.api_url, identity)
        for completed in journal.data["records"].values():
            if completed["state"] == "done":
                completed.pop("before_ids", None)
        store = Resources(client, journal, context)
        languages, taxes = preflight(store, identity, products)
        live_categories = {int(row["id"]): row for row in store.all("categories")}
        # Wszystkie przypisania sprawdzamy przed pierwszym POST, także poza selekcją.
        resolved = {
            p["product_id"]: category_ids(p, mapping, live_categories) for p in products
        }
        if dry_run:
            report["planned_combinations"] = sum(
                len(p["price_plan"]["variants"]) for p in selected
            )
        else:
            for offset, source in enumerate(selected, 1):
                sid = source["product_id"]
                try:
                    reference = "pandomu-" + sid
                    existing = store.find("products", {"reference": reference})
                    if existing:
                        expected = {
                            v["reference"] for v in source["price_plan"]["variants"]
                        }
                        saved = [
                            v
                            for v in store.all("combinations")
                            if str(v["id_product"]) == str(existing["id"])
                        ]
                        if any(v["reference"] not in expected for v in saved):
                            raise ValueError(
                                "Zastane kombinacje nie występują w źródle; wymagane ręczne uzgodnienie."
                            )
                    cats, default = resolved[sid]
                    features = feature_ids(store, source["features"], languages)
                    desired = {
                        "reference": reference,
                        "name": translated(source["name"], languages),
                        "description": translated(source["description"], languages),
                        "description_short": translated(
                            source["description_short"], languages
                        ),
                        "link_rewrite": translated(
                            product_slug(source["name"], source["product_id"]),
                            languages,
                        ),
                        "price": source["price_plan"]["net"],
                        "weight": source["weight"],
                        "id_category_default": str(default),
                        "id_tax_rules_group": str(taxes[source["price_plan"]["vat"]]),
                        "product_type": "combinations"
                        if source["price_plan"]["variants"]
                        else "standard",
                        "state": "1",
                        "associations": {
                            "categories": [{"id": str(cid)} for cid in cats],
                            "product_features": features,
                        },
                    }
                    product, owned = store.sync(
                        "products",
                        reference,
                        {"reference": reference},
                        desired,
                        new_defaults={
                            "active": "0",
                            "id_shop_default": str(context["shop_id"]),
                            "available_for_order": "1",
                            "show_price": "1",
                            "visibility": "both",
                            "minimal_quantity": "1",
                            "condition": "new",
                        },
                    )
                    pid = int(product["id"])
                    combination_ids = {}
                    for variant in sorted(
                        source["price_plan"]["variants"],
                        key=lambda v: int(v["source_id"]),
                    ):
                        options = option_ids(store, variant["attributes"], languages)
                        combination, _ = store.sync(
                            "combinations",
                            variant["reference"],
                            {"reference": variant["reference"]},
                            {
                                "id_product": str(pid),
                                "reference": variant["reference"],
                                "price": variant["modifier"],
                                "weight": "0.000000",
                                "minimal_quantity": "1",
                                "associations": {"product_option_values": options},
                            },
                        )
                        combination_ids[variant["source_id"]] = int(combination["id"])
                    stock = seed_stock(
                        store,
                        source,
                        product,
                        owned,
                        combination_ids,
                        stocks[sid],
                        reset_stock,
                    )
                    report["products"].append(
                        {
                            "source_id": sid,
                            "id": pid,
                            "weight_origin": source["weight_origin"],
                            "weight_rule": source["weight_rule"],
                            **stock,
                        }
                    )
                    print(
                        f"[{offset}/{len(selected)}] OK {reference}, ID {pid}, stock {stock['quantity']}",
                        flush=True,
                    )
                except (ValueError, PrestaShopAPIError) as exc:
                    report["errors"].append({"source_id": sid, "error": str(exc)})
                    print(f"[{offset}/{len(selected)}] ERROR {sid}: {exc}", flush=True)
        report["resources"] = {key: dict(value) for key, value in store.counts.items()}
    report["duration_seconds"] = round(time.monotonic() - started, 3)
    report["unavailable_products"] = sum(
        row["quantity"] == 0 for row in report["products"]
    )
    return report


def write_report(path, report):
    atomic_json(path, report)
