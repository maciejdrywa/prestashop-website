from __future__ import annotations

from decimal import Decimal
import json
import logging
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from .models import ProductItem, VariantItem
from .pricing import (
    to_decimal,
    round_netto,
    round_gross,
    calculate_gross,
    calculate_netto,
    calculate_price_modifier,
)
from .stock import validate_stock_quantity, verify_stock_ratio

logger = logging.getLogger("product_import")

# Limity długości pól PrestaShop
MAX_PRODUCT_NAME_LENGTH = 128
MAX_DESCRIPTION_SHORT_LENGTH = 800

# Deterministyczne reguły wagi demonstracyjnej na podstawie kategorii (zgodne z deploy/init/lib/weights.php)
CATEGORY_WEIGHT_RULES: Dict[str, Decimal] = {
    "uchwyty meblowe": Decimal("0.100000"),
    "wizjery": Decimal("0.050000"),
    "wkładki do drzwi": Decimal("0.300000"),
    "zamki drzwiowe": Decimal("1.000000"),
    "samozamykacze": Decimal("2.000000"),
    "klamki do drzwi": Decimal("0.500000"),
    "akcesoria meblowe": Decimal("0.200000"),
    "bramy i ogrodzenia": Decimal("1.500000"),
    "okucia budowlane": Decimal("1.000000"),
    "okucia drzwiowe": Decimal("0.500000"),
    "dom i ogród": Decimal("1.000000"),
    "produkty polecane": Decimal("1.000000"),
}
DEFAULT_DEMO_WEIGHT = Decimal("1.000000")


def resolve_product_weight(raw_weight: Any, category_paths: List[List[str]]) -> Tuple[Decimal, str]:
    """
    Wyznacza masę produktu:
    1. Jeśli waga źródłowa jest zdefiniowana i > 0, zwraca (waga, 'source').
    2. W przypadku braku wagi źródłowej dobiera masę demonstracyjną z najgłębszej pasującej kategorii.
    """
    if raw_weight is not None:
        try:
            w_dec = round_netto(raw_weight)
            if w_dec > Decimal("0"):
                return w_dec, "source"
        except (ValueError, TypeError):
            pass

    # Dobór masy demonstracyjnej wg hierarchii kategorii
    candidates: List[Tuple[int, str, Decimal]] = []
    for path in category_paths:
        for depth, cat_name in enumerate(path):
            norm_name = cat_name.strip().lower()
            if norm_name in CATEGORY_WEIGHT_RULES:
                candidates.append((depth, norm_name, CATEGORY_WEIGHT_RULES[norm_name]))

    if candidates:
        # Najgłębsza kategoria ma pierwszeństwo, remisy rozstrzygane alfabetycznie
        candidates.sort(key=lambda c: (-c[0], c[1]))
        return candidates[0][2], "demo"

    return DEFAULT_DEMO_WEIGHT, "demo"


def validate_variant_record(raw: Dict[str, Any], base_netto: Decimal) -> VariantItem:
    """Waliduje pojedynczy rekord wariantu z products.json."""
    vid = str(raw.get("variant_id") or "").strip()
    if not vid:
        raise ValueError("Wariant produktu nie posiada wymaganego pola 'variant_id'.")

    name = str(raw.get("name") or f"Wariant {vid}").strip()
    attributes = raw.get("attributes") or {}
    if not isinstance(attributes, dict):
        attributes = {}

    v_netto = round_netto(raw.get("price_netto", base_netto))
    v_brutto = round_gross(raw.get("price_brutto", Decimal("0.00")))
    
    # Modyfikator ceny
    raw_mod = raw.get("price_modifier")
    if raw_mod is not None:
        price_modifier = round_netto(raw_mod)
    else:
        price_modifier = calculate_price_modifier(v_netto, base_netto)

    raw_stock = raw.get("stock")
    stock = validate_stock_quantity(raw_stock, f"stan wariantu {vid}") if raw_stock is not None else None

    return VariantItem(
        variant_id=vid,
        name=name,
        attributes={str(k).strip(): str(v).strip() for k, v in attributes.items()},
        price_modifier=price_modifier,
        price_netto=v_netto,
        price_brutto=v_brutto,
        stock=stock,
        raw_data=raw,
    )


def validate_product_record(raw: Dict[str, Any]) -> ProductItem:
    """Waliduje pojedynczy rekord produktu ze słownika products.json."""
    pid = str(raw.get("product_id") or "").strip()
    if not pid:
        raise ValueError("Produkt nie posiada wymaganego identyfikatora 'product_id'.")

    name = str(raw.get("name") or "").strip()
    if not name:
        raise ValueError(f"Produkt {pid} posiada pustą nazwę.")
    if len(name) > MAX_PRODUCT_NAME_LENGTH:
        logger.warning(
            "Nazwa produktu %s przekracza limit %d znaków (długość: %d). Przycięto nazwę.",
            pid,
            MAX_PRODUCT_NAME_LENGTH,
            len(name),
        )
        name = name[:MAX_PRODUCT_NAME_LENGTH]

    # Opisy
    description = str(raw.get("description") or "").strip()
    description_short = str(raw.get("description_short") or "").strip()

    # Ceny i stawka VAT
    vat_rate = to_decimal(raw.get("vat_rate", Decimal("23")), "stawka VAT")
    if vat_rate < Decimal("0"):
        raise ValueError(f"Produkt {pid} posiada ujemną stawkę VAT: {vat_rate}.")

    raw_netto = raw.get("price_netto")
    if raw_netto is not None:
        price_netto = round_netto(raw_netto)
    elif raw.get("price_brutto") is not None:
        price_netto = calculate_netto(raw["price_brutto"], vat_rate)
    else:
        raise ValueError(f"Produkt {pid} nie posiada ceny netto ani brutto.")

    if price_netto < Decimal("0"):
        raise ValueError(f"Produkt {pid} posiada ujemną cenę netto: {price_netto}.")

    raw_brutto = raw.get("price_brutto")
    if raw_brutto is not None:
        price_brutto = round_gross(raw_brutto)
    else:
        price_brutto = calculate_gross(price_netto, vat_rate)

    # Kategorie
    category_paths = raw.get("category_paths") or []
    if not isinstance(category_paths, list) or len(category_paths) == 0:
        raise ValueError(f"Produkt {pid} nie posiada przypisanych ścieżek kategorii ('category_paths').")

    category_source_urls = raw.get("category_source_urls") or []
    breadcrumbs = raw.get("breadcrumbs") or []

    # Waga
    weight, weight_origin = resolve_product_weight(raw.get("weight"), category_paths)

    # Warianty
    variants: List[VariantItem] = []
    raw_variants = raw.get("variants") or []
    if isinstance(raw_variants, list):
        for v in raw_variants:
            variants.append(validate_variant_record(v, price_netto))

    # Stan magazynowy
    raw_stock = raw.get("stock")
    stock = validate_stock_quantity(raw_stock, f"stan produktu {pid}") if raw_stock is not None else None

    # Zdjęcia
    images = [str(url).strip() for url in (raw.get("images") or []) if url]
    cover_image = raw.get("cover_image")
    if cover_image:
        cover_image = str(cover_image).strip()

    # Cechy
    features = raw.get("features") or raw.get("product_features") or {}
    if not isinstance(features, dict):
        features = {}

    return ProductItem(
        product_id=pid,
        name=name,
        description=description,
        description_short=description_short,
        price_netto=price_netto,
        price_brutto=price_brutto,
        vat_rate=vat_rate,
        weight=weight,
        weight_origin=weight_origin,
        images=images,
        cover_image=cover_image,
        variants=variants,
        stock=stock,
        category_paths=category_paths,
        category_source_urls=[str(u) for u in category_source_urls],
        breadcrumbs=[str(b) for b in breadcrumbs],
        product_url=raw.get("product_url"),
        features={str(k): str(v) for k, v in features.items()},
        raw_data=raw,
    )


def load_and_validate_products(
    file_path: Path | str,
    validation_errors: Optional[List[str]] = None,
) -> List[ProductItem]:
    """
    Wczytuje i waliduje plik products.json.
    Prawidłowe rekordy zwraca w liście; błędy pojedynczych pozycji rejestruje
    w validation_errors, nie przerywając przetwarzania pozostałych.
    """
    p = Path(file_path)
    if not p.is_file():
        raise FileNotFoundError(f"Plik katalogu produktów nie istnieje: {p}")

    with p.open("r", encoding="utf-8") as f:
        data = json.load(f)

    if not isinstance(data, list):
        raise ValueError(f"Niepoprawny format pliku {p}: oczekiwano listy produktów.")

    items: List[ProductItem] = []
    seen_ids: set[str] = set()

    all_stock_values: List[int] = []

    for idx, raw in enumerate(data):
        try:
            item = validate_product_record(raw)
            if item.product_id in seen_ids:
                raise ValueError(f"Zduplikowany identyfikator product_id '{item.product_id}' (pozycja {idx + 1}).")
            seen_ids.add(item.product_id)
            items.append(item)

            if item.stock is not None:
                all_stock_values.append(item.stock)
            for v in item.variants:
                if v.stock is not None:
                    all_stock_values.append(v.stock)

        except Exception as exc:
            err = f"Błąd walidacji rekordu {idx + 1} (product_id: {raw.get('product_id', '?')}): {exc}"
            logger.warning(err)
            if validation_errors is not None:
                validation_errors.append(err)

    if all_stock_values:
        valid_ratio, percentage = verify_stock_ratio(all_stock_values)
        if not valid_ratio:
            logger.warning(
                "Odsetek zerowych stanów magazynowych wynosi %.2f%% (wymagany przedział: 5%% - 20%%).",
                percentage,
            )

    logger.info("Wczytano i zwalidowano %d poprawnych produktów z pliku: %s", len(items), p)
    return items
