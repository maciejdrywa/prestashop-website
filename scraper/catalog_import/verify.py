"""Niezależny odczyt całego katalogu przed aktywacją nowych produktów."""

import json
from decimal import Decimal

from product_import.planning import CENT, distribute_stock, rounded, stock_plan
from product_import.resources import Resources
from product_import.sync import category_ids, preflight


def verify_initial_stock(source, quantities, saved, total):
    variants = source["price_plan"]["variants"]
    if quantities[0] != total:
        raise ValueError("Niezgodny początkowy magazyn: " + source["product_id"])
    if not variants:
        return
    expected = distribute_stock(total, [v["source_id"] for v in variants])
    by_reference = {row["reference"]: int(row["id"]) for row in saved}
    if any(
        quantities[by_reference[v["reference"]]] != expected[v["source_id"]]
        for v in variants
    ):
        raise ValueError("Niezgodny początkowy magazyn: " + source["product_id"])


def verify_catalog(
    client, products, mapping, context, product_state, image_report, initial_stock=False
):
    store = Resources(client, None, context)
    preflight(store, client.instance_identity, products)
    live = store.all("products")
    combinations = store.all("combinations")
    stocks = store.all("stock_availables")
    categories = {int(row["id"]): row for row in store.all("categories")}
    records = json.loads(product_state.read_text())["records"]
    galleries = {row["source_id"]: row for row in image_report["products"]}
    plan = stock_plan(products)
    result = {
        "products": 0,
        "combinations": 0,
        "images": 0,
        "zero_stock": 0,
        "active": 0,
        "prices_ok": True,
        "stock_ok": True,
        "galleries_ok": True,
    }
    for source in products:
        sid = source["product_id"]
        ref = "pandomu-" + sid
        candidates = [p for p in live if p.get("reference") == ref]
        if len(candidates) != 1:
            raise ValueError("Niejednoznaczna lub brakująca referencja: " + ref)
        product = candidates[0]
        pid = int(product["id"])
        entry = records["products:" + ref]
        desired = {**entry["desired"], **entry.get("confirmed_html", {})}
        if entry["id"] != pid or not store.matches("products", product, desired):
            raise ValueError("Weryfikacja pól produktu nieudana: " + ref)
        cats, default = category_ids(source, mapping, categories)
        if (
            int(product["id_category_default"]) != default
            or sorted(int(row["id"]) for row in product["associations"]["categories"])
            != cats
        ):
            raise ValueError("Niezgodne przypisania kategorii: " + ref)
        if Decimal(product["price"]) != Decimal(source["price_plan"]["net"]) or Decimal(
            product["weight"]
        ) != Decimal(source["weight"]):
            raise ValueError("Niezgodna cena lub waga: " + ref)
        saved = [c for c in combinations if int(c["id_product"]) == pid]
        expected = source["price_plan"]["variants"]
        if len(saved) != len(expected):
            raise ValueError("Niezgodna liczba kombinacji: " + ref)
        quantities = [s for s in stocks if int(s["id_product"]) == pid]
        if any(
            int(s.get("id_shop", 0)) != context["shop_id"]
            or int(s.get("id_shop_group", 0)) != 0
            or str(s.get("depends_on_stock")) != "0"
            for s in quantities
        ):
            raise ValueError("Nieobsługiwany magazyn: " + ref)
        by_variant = {
            int(s["id_product_attribute"]): int(s["quantity"]) for s in quantities
        }
        if len(by_variant) != len(quantities) or set(by_variant) != {
            0,
            *(int(c["id"]) for c in saved),
        }:
            raise ValueError("Niekompletny magazyn: " + ref)
        if saved and by_variant[0] != sum(q for vid, q in by_variant.items() if vid):
            raise ValueError("Niepoprawna suma magazynu: " + ref)
        for variant in expected:
            match = [c for c in saved if c["reference"] == variant["reference"]]
            if len(match) != 1:
                raise ValueError("Niejednoznaczna kombinacja: " + variant["reference"])
            combination = match[0]
            desired_combination = records["combinations:" + variant["reference"]][
                "desired"
            ]
            if not store.matches("combinations", combination, desired_combination):
                raise ValueError("Niezgodne pola kombinacji: " + variant["reference"])
            if rounded(
                (Decimal(product["price"]) + Decimal(combination["price"]))
                * (1 + Decimal(source["price_plan"]["vat"]) / 100),
                CENT,
            ) != Decimal(variant["gross"]):
                raise ValueError(
                    "Niezgodna cena brutto kombinacji: " + variant["reference"]
                )
        if saved:
            flagged = [int(c["id"]) for c in saved if str(c.get("default_on")) == "1"]
            if (
                len(flagged) != 1
                or int(product.get("cache_default_attribute", 0)) != flagged[0]
            ):
                raise ValueError("Nieprawidłowy wariant domyślny: " + ref)
        if initial_stock:
            verify_initial_stock(source, by_variant, saved, plan[sid])
        gallery = galleries[sid]
        ids = [int(row["id"]) for row in product["associations"].get("images", [])]
        if (
            ids != gallery["image_ids"]
            or not ids
            or int(product.get("id_default_image", 0)) != gallery["cover_id"]
        ):
            raise ValueError("Niezgodna galeria lub okładka: " + ref)
        result["products"] += 1
        result["combinations"] += len(saved)
        result["images"] += len(ids)
        result["zero_stock"] += by_variant[0] == 0
        result["active"] += str(product["active"]) == "1"
    return result
