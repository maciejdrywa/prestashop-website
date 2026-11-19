"""Walidacja katalogu i reguły cen, masy oraz magazynu zgodne z importerem PHP."""

from __future__ import annotations

import hashlib
import json
import re
import unicodedata
from decimal import ROUND_HALF_UP, Decimal, InvalidOperation
from pathlib import Path

from bs4 import BeautifulSoup
from category_import.utils import PL_CHAR_TRANSLATION

SIX = Decimal("0.000001")
CENT = Decimal("0.01")
WEIGHTS = {
    "uchwyty meblowe": ".1",
    "wizjery": ".05",
    "wkładki do drzwi": ".3",
    "zamki drzwiowe": "1",
    "samozamykacze": "2",
    "klamki do drzwi": ".5",
    "akcesoria meblowe": ".2",
    "bramy i ogrodzenia": "1.5",
    "okucia budowlane": "1",
    "okucia drzwiowe": ".5",
    "dom i ogród": "1",
    "produkty polecane": "1",
}


def product_slug(name, source_id):
    """Tools::str2url dla polskiego katalogu (interpunkcja jest usuwana)."""
    value = unicodedata.normalize(
        "NFKD", name.strip().lower().translate(PL_CHAR_TRANSLATION)
    )
    value = value.encode("ascii", "ignore").decode("ascii")
    value = re.sub(r"[^a-z0-9\s':/\[\]-]", "", value)
    value = re.sub(r"[\s':/\[\]-]+", "-", value)
    return value[:128].rstrip("-") or f"pandomu-{source_id}"


def number(value, label="liczba", negative=False):
    try:
        if isinstance(value, bool) or value is None:
            raise ValueError()
        result = Decimal(str(value))
        if not result.is_finite() or (result < 0 and not negative):
            raise ValueError()
        return result
    except (ValueError, InvalidOperation):
        raise ValueError(f"Niepoprawna wartość: {label}.") from None


def rounded(value, scale=SIX):
    return number(value, negative=True).quantize(scale, rounding=ROUND_HALF_UP)


def identifier(value, label):
    if isinstance(value, bool) or not re.fullmatch(r"[1-9][0-9]*", str(value)):
        raise ValueError(f"Niepoprawne ID {label}.")
    return str(value)


def text(value, label, limit=128):
    if (
        not isinstance(value, str)
        or not value.strip()
        or len(value) > limit
        or re.search(r"[<>={}\x00-\x1f]", value)
    ):
        raise ValueError(f"Niepoprawny tekst {label}.")
    return value.strip()


def description(value, label, required=True):
    if not isinstance(value, str) or len(value) > 4_194_303:
        raise ValueError(f"Niepoprawny opis {label}.")
    soup = BeautifulSoup(value, "html.parser")
    if required and not soup.get_text(strip=True):
        raise ValueError(f"Pusty opis {label}.")
    if soup.find(["script", "iframe", "object", "embed", "form", "style"]) or any(
        str(key).lower().startswith("on")
        or str(val).strip().lower().startswith(("javascript:", "data:"))
        for node in soup.find_all(True)
        for key, val in node.attrs.items()
    ):
        raise ValueError(f"Niebezpieczny HTML {label}.")
    return value


def price_plan(source):
    vat = number(source.get("vat_rate"), "VAT")
    if vat > 100:
        raise ValueError("Stawka VAT przekracza 100%.")
    factor = 1 + vat / 100
    gross = rounded(number(source.get("price_brutto"), "brutto"), CENT)
    source_net = number(source.get("price_netto"), "netto")

    def check(net, target):
        if abs(net * factor - target) > Decimal("0.005002"):
            raise ValueError("Niezgodne netto, brutto i VAT źródła.")

    check(source_net, gross)
    net = rounded(gross / factor)
    result = {
        "net": str(net),
        "gross": str(gross),
        "vat": str(vat),
        "variants": [],
        "adjustments": [],
    }
    variants = source.get("variants")
    if not isinstance(variants, list):
        raise ValueError("Niepoprawna lista wariantów.")
    seen_ids, seen_values, group_set = set(), set(), None
    for variant in variants:
        if not isinstance(variant, dict):
            raise ValueError("Niepoprawny wariant.")
        vid = identifier(variant.get("variant_id"), "wariantu")
        attributes = variant.get("attributes")
        if not isinstance(attributes, dict) or not attributes:
            raise ValueError("Brak atrybutów wariantu.")
        attributes = dict(
            sorted(
                (text(k, "grupa atrybutu", 64), text(v, "wartość atrybutu"))
                for k, v in attributes.items()
            )
        )
        groups = tuple(attributes)
        signature = tuple(attributes.items())
        if (
            vid in seen_ids
            or signature in seen_values
            or (group_set is not None and groups != group_set)
        ):
            raise ValueError("Powielony wariant lub niespójne grupy atrybutów.")
        group_set = groups
        seen_ids.add(vid)
        seen_values.add(signature)
        raw = rounded(
            number(variant.get("price_modifier"), "modyfikator", negative=True)
        )
        observed = number(variant.get("price_netto"), "netto wariantu")
        target = rounded(number(variant.get("price_brutto"), "brutto wariantu"), CENT)
        check(observed, target)
        if abs(source_net + raw - observed) > Decimal("0.0000011"):
            raise ValueError("Modyfikator nie odpowiada cenie netto wariantu.")
        modifier = raw
        if rounded((net + modifier) * factor, CENT) != target:
            candidates = [
                raw + SIX * offset for step in range(1, 6) for offset in (step, -step)
            ]
            modifier = next(
                (v for v in candidates if rounded((net + v) * factor, CENT) == target),
                rounded(target / factor) - net,
            )
        if net + modifier < 0 or rounded((net + modifier) * factor, CENT) != target:
            raise ValueError("Nie można odtworzyć ceny wariantu.")
        if modifier != raw:
            result["adjustments"].append(
                {
                    "variant_id": vid,
                    "source_modifier": str(raw),
                    "import_modifier": str(modifier),
                }
            )
        result["variants"].append(
            {
                "source_id": vid,
                "reference": f"pandomu-{source['product_id']}-combination-{vid}",
                "attributes": attributes,
                "modifier": str(modifier),
                "gross": str(target),
            }
        )
    return result


def weight_plan(source):
    if source.get("weight") is not None:
        weight = rounded(number(source["weight"], "waga"))
        if weight > 0:
            return {
                "weight": str(weight),
                "weight_origin": "source",
                "weight_rule": "source",
            }
    candidates = [
        (depth, name.lower())
        for path in source["category_paths"]
        for depth, name in enumerate(path)
        if name.lower() in WEIGHTS
    ]
    candidates.sort(key=lambda entry: (-entry[0], entry[1]))
    rule = candidates[0][1] if candidates else "default"
    return {
        "weight": str(rounded(WEIGHTS.get(rule, "1"))),
        "weight_origin": "demo",
        "weight_rule": rule,
    }


def validate_product(raw):
    if not isinstance(raw, dict):
        raise ValueError("Rekord produktu nie jest obiektem.")
    source = dict(raw)
    source["product_id"] = identifier(raw.get("product_id"), "produktu")
    source["name"] = text(raw.get("name"), "nazwa produktu")
    source["description"] = description(raw.get("description"), "pełny")
    source["description_short"] = description(
        raw.get("description_short"), "krótki", required=False
    )
    paths = raw.get("category_paths")
    if (
        not isinstance(paths, list)
        or not paths
        or any(not isinstance(p, list) or not p for p in paths)
    ):
        raise ValueError("Brak pełnych ścieżek kategorii.")
    source["category_paths"] = [[text(n, "kategoria") for n in p] for p in paths]
    features = raw.get("features", [])
    if not isinstance(features, list):
        raise ValueError("Niepoprawna lista cech.")
    pairs = []
    for feature in features:
        if not isinstance(feature, dict):
            raise ValueError("Niepoprawna cecha.")
        pairs.append(
            (
                text(feature.get("name"), "cecha"),
                text(feature.get("value"), "wartość cechy", 255),
            )
        )
    if len({name for name, _ in pairs}) != len(pairs):
        raise ValueError("Powielona nazwa cechy.")
    source["features"] = [dict(name=n, value=v) for n, v in sorted(pairs)]
    source["price_plan"] = price_plan(source)
    source.update(weight_plan(source))
    primary = raw.get("breadcrumbs", [])[1:-1]
    if primary not in source["category_paths"]:
        primary = sorted(
            source["category_paths"], key=lambda p: (-len(p), " > ".join(p))
        )[0]
    source["primary"] = primary
    return source


def read_catalog(path: Path):
    records = json.loads(path.read_text(encoding="utf-8"), parse_float=Decimal)
    if not isinstance(records, list) or not records:
        raise ValueError("Oczekiwano niepustej listy produktów.")
    ids = [
        identifier(raw.get("product_id"), "produktu") if isinstance(raw, dict) else None
        for raw in records
    ]
    if None in ids or len(set(ids)) != len(ids):
        raise ValueError("Brakujące lub powielone ID produktu; zapis zablokowany.")
    valid, errors = [], []
    for raw in records:
        try:
            valid.append(validate_product(raw))
        except ValueError as exc:
            errors.append(
                {"source_id": str(raw.get("product_id", "?")), "error": str(exc)}
            )
    return valid, errors


def stock_plan(products):
    count = len(products)
    minimum = (count + 19) // 20
    maximum = count // 5
    if not count or minimum > maximum:
        raise ValueError("Katalog jest za mały, aby zapewnić 5–20% zerowych stanów.")
    zero_count = max(
        minimum, min(maximum, int(rounded(Decimal(count) / 10, Decimal("1"))))
    )
    ids = [p["product_id"] for p in products]
    if len(set(ids)) != count:
        raise ValueError("Powielone ID w planie magazynu.")
    ids.sort(
        key=lambda sid: (
            hashlib.sha256(("stock-v1:unavailable:" + sid).encode()).hexdigest(),
            sid,
        )
    )
    return {
        sid: 0
        if i < zero_count
        else 1
        + int(hashlib.sha256(("stock-v1:quantity:" + sid).encode()).hexdigest()[:8], 16)
        % 10
        for i, sid in enumerate(ids)
    }


def distribute_stock(total, variant_ids):
    ids = sorted(variant_ids, key=int)
    if not 0 <= total <= 10 or not ids or len(ids) != len(set(ids)):
        raise ValueError("Niepoprawny podział stanu wariantów.")
    return {
        sid: total // len(ids) + (i < total % len(ids)) for i, sid in enumerate(ids)
    }
