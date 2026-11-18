from __future__ import annotations

import logging
import re
from typing import Any, Dict, List, Optional, Tuple

try:
    from prestashop_api import PrestaShopClient
except ImportError:
    from scraper.prestashop_api import PrestaShopClient

from .models import ProductItem, VariantItem
from .pricing import (
    format_decimal,
    get_tax_rules_group_id,
    round_netto,
)
from .utils import slugify

logger = logging.getLogger("product_import")

_PRODUCT_MANAGED_FIELDS = (
    "name",
    "description",
    "description_short",
    "link_rewrite",
    "price",
    "id_tax_rules_group",
    "id_category_default",
    "weight",
    "active",
)


def fetch_active_languages(client: PrestaShopClient) -> List[str]:
    """Pobiera identyfikatory wszystkich aktywnych języków w sklepie PrestaShop (domyślnie ['1'])."""
    try:
        res = client.get("languages", params={"display": "[id]", "filter[active]": "1"})
        raw = res.get("languages", []) if isinstance(res, dict) else res
        if isinstance(raw, dict) and "language" in raw:
            langs = raw["language"] if isinstance(raw["language"], list) else [raw["language"]]
        elif isinstance(raw, list):
            langs = raw
        else:
            langs = []
        lang_ids = [str(lang["id"]) for lang in langs if isinstance(lang, dict) and "id" in lang]
        return lang_ids if lang_ids else ["1"]
    except Exception as exc:
        logger.debug("Nie udało się pobrać listy aktywnych języków (%s), użyto ID domyślnego '1'.", exc)
        return ["1"]


def fetch_existing_products_index(client: PrestaShopClient) -> Tuple[Dict[str, Dict[str, Any]], Dict[int, Dict[str, Any]]]:
    """
    Pobiera zbiorczy indeks istniejących produktów ze sklepu.
    Zwraca krotkę: (by_reference, by_id).
    """
    logger.info("Pobieranie istniejących produktów z PrestaShop API...")
    res = client.get(
        "products",
        params={
            "display": "[id,reference,price,id_tax_rules_group,id_category_default,weight,active,name,description,description_short]"
        },
    )
    raw = res.get("products", []) if isinstance(res, dict) else res
    if isinstance(raw, dict):
        products_list = [raw]
    elif isinstance(raw, list):
        products_list = raw
    else:
        products_list = []

    by_ref: Dict[str, Dict[str, Any]] = {}
    by_id: Dict[int, Dict[str, Any]] = {}

    for p in products_list:
        if not isinstance(p, dict):
            continue
        try:
            pid = int(p["id"])
            ref = str(p.get("reference") or "").strip()
            by_id[pid] = p
            if ref:
                by_ref[ref] = p
        except (ValueError, KeyError):
            continue

    logger.info("Zindeksowano %d istniejących produktów w PrestaShop.", len(by_id))
    return by_ref, by_id


def fetch_existing_combinations_index(client: PrestaShopClient) -> Dict[str, Dict[str, Any]]:
    """
    Pobiera zbiorczy indeks istniejących kombinacji/wariantów ze sklepu.
    Zwraca słownik indeksowany unikalną referencją kombinacji (reference -> comb_dict).
    """
    logger.info("Pobieranie istniejących kombinacji z PrestaShop API...")
    res = client.get(
        "combinations",
        params={"display": "[id,id_product,reference,price,default_on]"},
    )
    raw = res.get("combinations", []) if isinstance(res, dict) else res
    if isinstance(raw, dict):
        combs_list = [raw]
    elif isinstance(raw, list):
        combs_list = raw
    else:
        combs_list = []

    by_ref: Dict[str, Dict[str, Any]] = {}
    for c in combs_list:
        if not isinstance(c, dict):
            continue
        ref = str(c.get("reference") or "").strip()
        if ref:
            by_ref[ref] = c

    logger.info("Zindeksowano %d istniejących kombinacji w PrestaShop.", len(by_ref))
    return by_ref


def fetch_product_options_cache(client: PrestaShopClient) -> Dict[str, int]:
    """Pobiera i keszuje istniejące grupy atrybutów (product_options) wg znormalizowanej nazwy."""
    res = client.get("product_options", params={"display": "[id,name]"})
    raw = res.get("product_options", []) if isinstance(res, dict) else res
    items = raw if isinstance(raw, list) else [raw] if isinstance(raw, dict) else []

    cache: Dict[str, int] = {}
    for item in items:
        if isinstance(item, dict) and "id" in item and "name" in item:
            name_val = item["name"]
            if isinstance(name_val, list) and name_val:
                name_str = name_val[0].get("value", "")
            else:
                name_str = str(name_val)
            cache[name_str.strip().lower()] = int(item["id"])
    return cache


def fetch_option_values_cache(client: PrestaShopClient) -> Dict[Tuple[int, str], int]:
    """Pobiera i keszuje istniejące wartości atrybutów (product_option_values) wg (id_attribute_group, nazwa)."""
    res = client.get("product_option_values", params={"display": "[id,id_attribute_group,name]"})
    raw = res.get("product_option_values", []) if isinstance(res, dict) else res
    items = raw if isinstance(raw, list) else [raw] if isinstance(raw, dict) else []

    cache: Dict[Tuple[int, str], int] = {}
    for item in items:
        if isinstance(item, dict) and "id" in item and "id_attribute_group" in item and "name" in item:
            gid = int(item["id_attribute_group"])
            name_val = item["name"]
            if isinstance(name_val, list) and name_val:
                name_str = name_val[0].get("value", "")
            else:
                name_str = str(name_val)
            cache[(gid, name_str.strip().lower())] = int(item["id"])
    return cache


def ensure_product_option_group(
    client: PrestaShopClient,
    group_name: str,
    options_cache: Dict[str, int],
    active_languages: List[str],
) -> int:
    """Zapewnia istnienie grupy atrybutów (np. 'WYBIERZ KOLOR'), tworząc ją w razie braku."""
    norm = group_name.strip().lower()
    if norm in options_cache:
        return options_cache[norm]

    langs = active_languages or ["1"]
    payload = {
        "name": {lid: group_name.strip() for lid in langs},
        "public_name": {lid: group_name.strip() for lid in langs},
        "group_type": "select",
    }
    res = client.post("product_options", payload)
    new_id = int(res["product_option"]["id"])
    options_cache[norm] = new_id
    logger.info("Utworzono nową grupę opcji w PrestaShop: '%s' (ID: %d)", group_name, new_id)
    return new_id


def ensure_product_option_value(
    client: PrestaShopClient,
    group_id: int,
    value_name: str,
    values_cache: Dict[Tuple[int, str], int],
    active_languages: List[str],
) -> int:
    """Zapewnia istnienie wartości atrybutu (np. 'brąz'), tworząc ją w razie braku."""
    norm = value_name.strip().lower()
    key = (group_id, norm)
    if key in values_cache:
        return values_cache[key]

    langs = active_languages or ["1"]
    payload = {
        "id_attribute_group": str(group_id),
        "name": {lid: value_name.strip() for lid in langs},
    }
    res = client.post("product_option_values", payload)
    new_id = int(res["product_option_value"]["id"])
    values_cache[key] = new_id
    logger.info("Utworzono nową wartość opcji w grupie %d: '%s' (ID: %d)", group_id, value_name, new_id)
    return new_id


def fetch_product_features_cache(client: PrestaShopClient) -> Dict[str, int]:
    """Pobiera i keszuje istniejące cechy produktów (product_features) wg znormalizowanej nazwy."""
    res = client.get("product_features", params={"display": "[id,name]"})
    raw = res.get("product_features", []) if isinstance(res, dict) else res
    items = raw if isinstance(raw, list) else [raw] if isinstance(raw, dict) else []

    cache: Dict[str, int] = {}
    for item in items:
        if isinstance(item, dict) and "id" in item and "name" in item:
            name_val = item["name"]
            if isinstance(name_val, list) and name_val:
                name_str = name_val[0].get("value", "")
            else:
                name_str = str(name_val)
            cache[name_str.strip().lower()] = int(item["id"])
    return cache


def fetch_feature_values_cache(client: PrestaShopClient) -> Dict[Tuple[int, str], int]:
    """Pobiera i keszuje istniejące wartości cech (product_feature_values) wg (id_feature, znormalizowana_wartość)."""
    res = client.get("product_feature_values", params={"display": "[id,id_feature,value]"})
    raw = res.get("product_feature_values", []) if isinstance(res, dict) else res
    items = raw if isinstance(raw, list) else [raw] if isinstance(raw, dict) else []

    cache: Dict[Tuple[int, str], int] = {}
    for item in items:
        if isinstance(item, dict) and "id" in item and "id_feature" in item and "value" in item:
            fid = int(item["id_feature"])
            val = item["value"]
            if isinstance(val, list) and val:
                val_str = val[0].get("value", "")
            else:
                val_str = str(val)
            cache[(fid, val_str.strip().lower())] = int(item["id"])
    return cache


def ensure_product_feature(
    client: PrestaShopClient,
    feature_name: str,
    features_cache: Dict[str, int],
    active_languages: List[str],
) -> int:
    """Zapewnia istnienie cechy produktu (product_feature), tworząc ją w razie braku."""
    norm = feature_name.strip().lower()
    if norm in features_cache:
        return features_cache[norm]

    langs = active_languages or ["1"]
    payload = {
        "name": {lid: feature_name.strip() for lid in langs},
    }
    res = client.post("product_features", payload)
    new_id = int(res["product_feature"]["id"])
    features_cache[norm] = new_id
    logger.info("Utworzono nową cechę produktu w PrestaShop: '%s' (ID: %d)", feature_name, new_id)
    return new_id


def ensure_product_feature_value(
    client: PrestaShopClient,
    feature_id: int,
    value_name: str,
    values_cache: Dict[Tuple[int, str], int],
    active_languages: List[str],
) -> int:
    """Zapewnia istnienie wartości cechy (product_feature_value), tworząc ją w razie braku."""
    norm = value_name.strip().lower()
    key = (feature_id, norm)
    if key in values_cache:
        return values_cache[key]

    langs = active_languages or ["1"]
    payload = {
        "id_feature": str(feature_id),
        "value": {lid: value_name.strip() for lid in langs},
    }
    res = client.post("product_feature_values", payload)
    new_id = int(res["product_feature_value"]["id"])
    values_cache[key] = new_id
    logger.info("Utworzono nową wartość cechy %d: '%s' (ID: %d)", feature_id, value_name, new_id)
    return new_id


def build_product_payload(
    product: ProductItem,
    default_category_id: int,
    associated_category_ids: List[int],
    active_languages: List[str],
    product_id: Optional[int] = None,
    existing_full_product: Optional[Dict[str, Any]] = None,
    product_features: Optional[List[Dict[str, str]]] = None,
) -> Dict[str, Any]:
    """Tworzy słownik danych produktu do wysłania w formacie XML przez PrestaShop Webservice."""
    langs = active_languages or ["1"]
    slug = slugify(product.name)[:128] or product.reference
    tax_group_id = get_tax_rules_group_id(product.vat_rate)

    # Zbuduj bazowe asocjacje kategorii
    associations: Dict[str, Any] = {
        "categories": [{"id": str(cid)} for cid in associated_category_ids]
    }

    # Ochrona istniejących asocjacji przy operacji PUT
    if existing_full_product and isinstance(existing_full_product, dict):
        existing_assoc = existing_full_product.get("associations") or {}
        for key in ("combinations", "images", "product_option_values", "product_features"):
            if key in existing_assoc and existing_assoc[key]:
                associations[key] = existing_assoc[key]

    # Nadpisz lub dołącz nowe cechy produktu, jeśli zostały podane
    if product_features:
        associations["product_features"] = product_features

    payload: Dict[str, Any] = {
        "reference": product.reference,
        "name": {lid: product.name for lid in langs},
        "description": {lid: product.description for lid in langs},
        "description_short": {lid: product.description_short for lid in langs},
        "link_rewrite": {lid: slug for lid in langs},
        "price": format_decimal(product.price_netto, 6),
        "id_tax_rules_group": tax_group_id,
        "id_category_default": default_category_id,
        "weight": format_decimal(product.weight, 6),
        "active": 1,
        "available_for_order": 1,
        "show_price": 1,
        "associations": associations,
    }

    if product_id is not None:
        payload["id"] = product_id
        if existing_full_product:
            # Zachowaj niemutowane przez nas pola PrestaShop
            for field in ("id_shop_default", "condition", "state", "date_add"):
                if field in existing_full_product and field not in payload:
                    payload[field] = existing_full_product[field]

    return payload


def is_product_dirty(
    existing: Dict[str, Any],
    product: ProductItem,
    default_category_id: int,
    associated_category_ids: Optional[List[int]] = None,
    product_features: Optional[List[Dict[str, str]]] = None,
) -> bool:
    """
    Sprawdza, czy produkt w PrestaShop wymaga aktualizacji (Dirty Checking).
    Porównuje: cenę, grupę podatkową, domyślną kategorię, masę, nazwę, opisy,
    status aktywności oraz ewentualne asocjacje.
    """
    try:
        # 1. Cena netto
        curr_price = round_netto(existing.get("price", 0))
        if curr_price != product.price_netto:
            return True

        # 2. Grupa podatkowa
        curr_tax = int(existing.get("id_tax_rules_group", 0))
        target_tax = get_tax_rules_group_id(product.vat_rate)
        if curr_tax != target_tax:
            return True

        # 3. Kategoria domyślna
        curr_cat = int(existing.get("id_category_default", 0))
        if curr_cat != default_category_id:
            return True

        # 4. Masa
        curr_weight = round_netto(existing.get("weight", 0))
        if curr_weight != product.weight:
            return True

        # 5. Status aktywności
        curr_active = str(existing.get("active", ""))
        if curr_active not in ("1", 1):
            return True

        # 6. Nazwa
        def _extract_text(val: Any) -> str:
            if isinstance(val, list) and val:
                raw_text = str(val[0].get("value", ""))
            else:
                raw_text = str(val or "")
            norm = raw_text.replace("\r\n", "\n").strip()
            # PrestaShop normalizuje samozamykające tagi HTML np. <br/> -> <br />
            return re.sub(r"(\S)/>", r"\1 />", norm)

        curr_name = _extract_text(existing.get("name"))
        if curr_name != _extract_text(product.name):
            return True

        # 7. Opis pełny (jeśli dostępny w obiekcie)
        if "description" in existing:
            curr_desc = _extract_text(existing.get("description"))
            if curr_desc != _extract_text(product.description):
                return True

        # 8. Opis krótki (jeśli dostępny w obiekcie)
        if "description_short" in existing:
            curr_desc_short = _extract_text(existing.get("description_short"))
            if curr_desc_short != _extract_text(product.description_short):
                return True

        # 9. Asocjacje kategorii (jeśli dostępne w obiekcie)
        if associated_category_ids is not None and "associations" in existing:
            assoc = existing.get("associations") or {}
            existing_cats = assoc.get("categories", [])
            existing_cat_ids = {str(c.get("id")) for c in existing_cats if isinstance(c, dict)}
            target_cat_ids = {str(cid) for cid in associated_category_ids}
            if existing_cat_ids and existing_cat_ids != target_cat_ids:
                return True

        # 10. Cechy produktu (jeśli dostępne w obiekcie)
        if product_features is not None and "associations" in existing:
            assoc = existing.get("associations") or {}
            existing_feats = assoc.get("product_features", [])
            existing_feat_pairs = {
                (str(f.get("id")), str(f.get("id_feature_value")))
                for f in existing_feats if isinstance(f, dict)
            }
            target_feat_pairs = {
                (str(f.get("id")), str(f.get("id_feature_value")))
                for f in product_features if isinstance(f, dict)
            }
            if existing_feat_pairs and existing_feat_pairs != target_feat_pairs:
                return True

        return False
    except Exception:
        return True


def build_combination_payload(
    product_id: int,
    source_product_id: str,
    variant: VariantItem,
    option_value_ids: List[int],
    is_default: bool,
    combination_id: Optional[int] = None,
) -> Dict[str, Any]:
    """Tworzy słownik danych kombinacji dla Webservice API."""
    ref = variant.combination_reference(source_product_id)
    payload: Dict[str, Any] = {
        "id_product": str(product_id),
        "reference": ref,
        "price": format_decimal(variant.price_modifier, 6),
        "default_on": "1" if is_default else "",
        "minimal_quantity": "1",
        "associations": {
            "product_option_values": [{"id": str(ovid)} for ovid in option_value_ids]
        },
    }
    if combination_id is not None:
        payload["id"] = combination_id
    return payload


def is_combination_dirty(
    existing: Dict[str, Any],
    variant: VariantItem,
    is_default: bool,
) -> bool:
    """Sprawdza czy istniejąca kombinacja uległa zmianie (cena/modyfikator, flaga default_on)."""
    try:
        curr_price = round_netto(existing.get("price", 0))
        if curr_price != variant.price_modifier:
            return True

        curr_default = str(existing.get("default_on") or "")
        target_default = "1" if is_default else ""
        if curr_default != target_default:
            return True

        return False
    except Exception:
        return True


def fetch_stock_availables_for_product(client: PrestaShopClient, product_id: int) -> List[Dict[str, Any]]:
    """Pobiera listę rekordów magazynowych (stock_availables) dla danego produktu i jego kombinacji."""
    res = client.get(
        "stock_availables",
        params={
            "filter[id_product]": str(product_id),
            "display": "[id,id_product,id_product_attribute,quantity,out_of_stock,depends_on_stock]",
        },
    )
    raw = res.get("stock_availables", []) if isinstance(res, dict) else res
    if isinstance(raw, list):
        return raw
    if isinstance(raw, dict):
        return [raw]
    return []


def update_stock_available(
    client: PrestaShopClient,
    stock_record_id: int,
    product_id: int,
    product_attribute_id: int,
    target_quantity: int,
    current_quantity: Optional[int] = None,
    current_out_of_stock: Optional[int] = None,
    current_depends_on_stock: Optional[int] = None,
) -> bool:
    """
    Aktualizuje stan magazynowy i wymusza out_of_stock=0 (blokada zamawiania przy braku towaru).
    Zwraca True jeśli wykonano PUT, lub False jeśli pominięto (dirty checking).
    """
    if (
        current_quantity is not None
        and str(current_quantity) == str(target_quantity)
        and str(current_out_of_stock) == "0"
        and (current_depends_on_stock is None or str(current_depends_on_stock) == "0")
    ):
        # Stan jest identyczny — pomijamy zbędne zapytanie sieciowe
        return False

    payload = {
        "id": str(stock_record_id),
        "id_product": str(product_id),
        "id_product_attribute": str(product_attribute_id),
        "id_shop": "1",
        "quantity": str(target_quantity),
        "out_of_stock": "0",
        "depends_on_stock": "0",
    }
    client.put("stock_availables", stock_record_id, payload)
    return True
