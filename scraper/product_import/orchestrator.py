from __future__ import annotations

import logging
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple, Union

try:
    from prestashop_api import PrestaShopClient
except ImportError:
    from scraper.prestashop_api import PrestaShopClient

from .mapping import (
    get_default_category_mapping_path,
    get_default_product_mapping_path,
    load_category_mapping,
    load_product_mapping,
    resolve_product_categories,
    save_product_mapping,
)
from .models import ProductImportStats, ProductItem
from .prestashop_sync import (
    build_combination_payload,
    build_product_payload,
    ensure_product_feature,
    ensure_product_feature_value,
    ensure_product_option_group,
    ensure_product_option_value,
    fetch_active_languages,
    fetch_existing_combinations_index,
    fetch_existing_products_index,
    fetch_feature_values_cache,
    fetch_option_values_cache,
    fetch_product_features_cache,
    fetch_product_options_cache,
    fetch_stock_availables_for_product,
    is_combination_dirty,
    is_product_dirty,
    update_stock_available,
)
from .stock import (
    distribute_stock_to_variants,
    generate_deterministic_stock_plan,
)
from .validator import load_and_validate_products

logger = logging.getLogger("product_import")

_CURRENT_DIR = Path(__file__).resolve().parent
_PROJECT_ROOT = _CURRENT_DIR.parent.parent


def import_products(
    client: Optional[PrestaShopClient] = None,
    products_file: Optional[Union[str, Path]] = None,
    category_mapping_file: Optional[Union[str, Path]] = None,
    product_mapping_file: Optional[Union[str, Path]] = None,
    update_existing: bool = True,
    limit: Optional[int] = None,
) -> Tuple[ProductImportStats, Dict[str, int]]:
    """
    Główna funkcja orkiestracji importu produktów, wariantów/kombinacji i stanów magazynowych.
    
    Parametry:
    - client: instancja PrestaShopClient (jeśli None, tworzona automatycznie ze zmiennych .env)
    - products_file: ścieżka do products.json (domyślnie scraper-results/products.json)
    - category_mapping_file: ścieżka do category_mapping.json
    - product_mapping_file: ścieżka wyjściowa do product_mapping.json
    - update_existing: czy aktualizować istniejące rekordy (PUT), czy je tylko pominąć
    - limit: opcjonalny limit liczby przetwarzanych produktów (np. do szybkich testów)
    
    Zwraca:
    - (ProductImportStats, product_mapping_dict)
    """
    prod_path = Path(products_file) if products_file else _PROJECT_ROOT / "scraper-results" / "products.json"
    cat_map_path = (
        Path(category_mapping_file) if category_mapping_file else get_default_category_mapping_path(_PROJECT_ROOT / "scraper-results")
    )
    prod_map_path = (
        Path(product_mapping_file) if product_mapping_file else get_default_product_mapping_path(_PROJECT_ROOT / "scraper-results")
    )

    if client is None:
        client = PrestaShopClient()
        client.check_connection()

    # 1. Wczytanie mapowania kategorii ze scraper-results/category_mapping.json
    category_mapping = load_category_mapping(cat_map_path)
    if not category_mapping:
        raise ValueError(f"Pusty lub brakujący plik mapowania kategorii: {cat_map_path}. Uruchom najpierw import kategorii.")

    # 2. Wczytanie i walidacja produktów z products.json
    validation_errors: List[str] = []
    products = load_and_validate_products(prod_path, validation_errors=validation_errors)

    all_pids = [p.product_id for p in products]
    deterministic_stocks = generate_deterministic_stock_plan(all_pids)

    if limit is not None and limit > 0:
        logger.info("Ograniczono import do pierwszych %d produktów (flaga limit).", limit)
        products = products[:limit]

    # 3. Pobranie metadanych PrestaShop (języki, indeksy produktów, kombinacji, opcji)
    active_languages = fetch_active_languages(client)
    existing_by_ref, existing_by_id = fetch_existing_products_index(client)
    existing_combs = fetch_existing_combinations_index(client)
    options_cache = fetch_product_options_cache(client)
    values_cache = fetch_option_values_cache(client)
    features_cache = fetch_product_features_cache(client)
    feature_values_cache = fetch_feature_values_cache(client)

    # 4. Istniejące mapowanie produktów
    product_mapping: Dict[str, int] = load_product_mapping(prod_map_path)

    stats = ProductImportStats(total=len(products) + len(validation_errors))
    if validation_errors:
        stats.errors += len(validation_errors)
        stats.error_details.extend(validation_errors)

    logger.info("Rozpoczęcie synchronizacji %d produktów...", len(products))

    for idx, p in enumerate(products, start=1):
        try:
            if not isinstance(p, ProductItem):
                raise TypeError(f"Niepoprawny typ rekordu w pętli importu (oczekiwano ProductItem, otrzymano {type(p).__name__}).")

            # A. Rozwiązanie kategorii
            default_cat_id, associated_cat_ids = resolve_product_categories(
                p.category_paths,
                p.breadcrumbs,
                category_mapping,
            )

            # A2. Rozwiązanie cech produktu (product_features)
            feature_associations: List[Dict[str, str]] = []
            if p.features:
                for f_name, f_val in p.features.items():
                    fid = ensure_product_feature(client, f_name, features_cache, active_languages)
                    fvid = ensure_product_feature_value(client, fid, f_val, feature_values_cache, active_languages)
                    feature_associations.append({"id": str(fid), "id_feature_value": str(fvid)})

            # B. Wyszukanie istniejącego produktu wyłącznie po unikalnej referencji (bez ryzykownego fallbacku ID)
            existing_prod = existing_by_ref.get(p.reference)
            is_new_product = (existing_prod is None)

            ps_product_id: Optional[int] = None

            if is_new_product:
                # Tworzenie nowego produktu (POST)
                payload = build_product_payload(
                    product=p,
                    default_category_id=default_cat_id,
                    associated_category_ids=associated_cat_ids,
                    active_languages=active_languages,
                    product_features=feature_associations,
                )
                res = client.post("products", payload)
                ps_product_id = int(res["product"]["id"])
                stats.created += 1
                logger.debug("[%d/%d] Utworzono produkt: %s (ID: %d)", idx, len(products), p.name, ps_product_id)
                # Aktualizacja indeksu lokalnego
                existing_by_ref[p.reference] = res["product"]
                existing_by_id[ps_product_id] = res["product"]
            else:
                assert existing_prod is not None
                ps_product_id = int(existing_prod["id"])
                if update_existing:
                    # Pobranie pełnego obiektu przed is_product_dirty()
                    full_prod = None
                    try:
                        res_prod = client.get("products", ps_product_id)
                        full_prod = res_prod.get("product") if isinstance(res_prod, dict) else None
                        if not isinstance(full_prod, dict) or not full_prod:
                            raise ValueError(f"Odpowiedź API dla produktu ID {ps_product_id} nie zawiera poprawnego słownika 'product'.")
                    except Exception as get_exc:
                        err_msg = f"Nie można pobrać pełnego produktu ID {ps_product_id} przed PUT (ochrona asocjacji): {get_exc}"
                        logger.error(err_msg)
                        stats.errors += 1
                        stats.error_details.append(err_msg)
                        continue  # Przerywamy dla tego rekordu, aby nie wyczyścić galerii i kombinacji

                    if is_product_dirty(
                        existing=full_prod,
                        product=p,
                        default_category_id=default_cat_id,
                        associated_category_ids=associated_cat_ids,
                        product_features=feature_associations,
                    ):
                        payload = build_product_payload(
                            product=p,
                            default_category_id=default_cat_id,
                            associated_category_ids=associated_cat_ids,
                            active_languages=active_languages,
                            product_id=ps_product_id,
                            existing_full_product=full_prod,
                            product_features=feature_associations,
                        )
                        client.put("products", ps_product_id, payload)
                        stats.updated += 1
                        logger.debug("[%d/%d] Zaktualizowano produkt ID %d: %s (PUT)", idx, len(products), ps_product_id, p.name)
                    else:
                        stats.skipped += 1
                        logger.debug("[%d/%d] Pominięto niezmieniony produkt ID %d: %s (dirty-checking)", idx, len(products), ps_product_id, p.name)
                else:
                    stats.skipped += 1

            # Aktualizacja mapowania
            product_mapping[p.product_id] = ps_product_id
            product_mapping[p.reference] = ps_product_id

            # C. Synchronizacja wariantów/kombinacji
            variant_comb_ids: Dict[str, int] = {}
            if p.has_variants:
                for v_idx, variant in enumerate(p.variants):
                    comb_ref = variant.combination_reference(p.product_id)
                    existing_comb = existing_combs.get(comb_ref)
                    # Rozwiązanie ID wartości atrybutów
                    opt_val_ids: List[int] = []
                    for attr_name, attr_val in variant.attributes.items():
                        gid = ensure_product_option_group(client, attr_name, options_cache, active_languages)
                        vid = ensure_product_option_value(client, gid, attr_val, values_cache, active_languages)
                        opt_val_ids.append(vid)

                    is_default = (v_idx == 0)
                    if existing_comb is None:
                        # Tworzenie nowej kombinacji (POST)
                        comb_payload = build_combination_payload(
                            product_id=ps_product_id,
                            source_product_id=p.product_id,
                            variant=variant,
                            option_value_ids=opt_val_ids,
                            is_default=is_default,
                        )
                        comb_res = client.post("combinations", comb_payload)
                        comb_id = int(comb_res["combination"]["id"])
                        variant_comb_ids[variant.variant_id] = comb_id
                        existing_combs[comb_ref] = comb_res["combination"]
                        stats.combinations_created += 1
                    else:
                        comb_id = int(existing_comb["id"])
                        variant_comb_ids[variant.variant_id] = comb_id
                        if update_existing:
                            # Pobranie pełnego obiektu kombinacji przed is_combination_dirty()
                            full_comb = None
                            try:
                                res_comb = client.get("combinations", comb_id)
                                full_comb = res_comb.get("combination") if isinstance(res_comb, dict) else None
                                if not isinstance(full_comb, dict) or not full_comb:
                                    raise ValueError(f"Odpowiedź API dla kombinacji ID {comb_id} nie zawiera poprawnego słownika 'combination'.")
                            except Exception as get_comb_exc:
                                err_msg = f"Nie można pobrać pełnej kombinacji ID {comb_id} (produkt {p.reference}): {get_comb_exc}"
                                logger.error(err_msg)
                                stats.errors += 1
                                stats.error_details.append(err_msg)
                                continue  # Nie wykonujemy PUT na podstawie niepełnego indeksu

                            if is_combination_dirty(
                                existing=full_comb,
                                variant=variant,
                                is_default=is_default,
                                option_value_ids=opt_val_ids,
                            ):
                                comb_payload = build_combination_payload(
                                    product_id=ps_product_id,
                                    source_product_id=p.product_id,
                                    variant=variant,
                                    option_value_ids=opt_val_ids,
                                    is_default=is_default,
                                    combination_id=comb_id,
                                    existing_full_combination=full_comb,
                                )
                                client.put("combinations", comb_id, comb_payload)
                                stats.combinations_updated += 1
                            else:
                                stats.combinations_skipped += 1
                        else:
                            stats.combinations_skipped += 1

            # D. Synchronizacja stanów magazynowych (stock_availables)
            stock_records = fetch_stock_availables_for_product(client, ps_product_id)
            stock_by_attr: Dict[str, Dict[str, Any]] = {
                str(s.get("id_product_attribute", "0")): s for s in stock_records
            }

            # Całkowity początkowy stan produktu wyznaczony deterministycznie (zawsze w przedziale [0, 10]):
            tot_stock = deterministic_stocks.get(p.product_id, 0)

            if not p.has_variants:
                # Produkt standardowy: aktualizacja rekordu id_product_attribute = 0
                base_stock_rec = stock_by_attr.get("0")
                if base_stock_rec is not None:
                    s_id = int(base_stock_rec["id"])
                    c_qty = int(base_stock_rec.get("quantity", -1))
                    c_out = int(base_stock_rec.get("out_of_stock", -1))
                    c_dos = int(base_stock_rec.get("depends_on_stock", -1))

                    if is_new_product:
                        target_qty = tot_stock
                    else:
                        # Istniejący produkt: nie resetuj bieżącego stanu ze sklepu
                        target_qty = c_qty if c_qty >= 0 else tot_stock

                    was_updated = update_stock_available(
                        client=client,
                        stock_record_id=s_id,
                        product_id=ps_product_id,
                        product_attribute_id=0,
                        target_quantity=target_qty,
                        current_quantity=c_qty,
                        current_out_of_stock=c_out,
                        current_depends_on_stock=c_dos,
                    )
                    if was_updated:
                        stats.stocks_updated += 1
                    else:
                        stats.stocks_skipped += 1
            else:
                # Podział całkowitego stanu (0-10) na warianty tak, by suma nie przekroczyła tot_stock
                variant_ids = [v.variant_id for v in p.variants]
                v_stock_map = distribute_stock_to_variants(tot_stock, variant_ids)

                for vid, comb_id in variant_comb_ids.items():
                    comb_stock_rec = stock_by_attr.get(str(comb_id))
                    if comb_stock_rec is not None:
                        s_id = int(comb_stock_rec["id"])
                        c_qty = int(comb_stock_rec.get("quantity", -1))
                        c_out = int(comb_stock_rec.get("out_of_stock", -1))
                        c_dos = int(comb_stock_rec.get("depends_on_stock", -1))

                        if is_new_product:
                            target_qty = v_stock_map.get(vid, 0)
                        else:
                            # Istniejąca kombinacja: nie resetuj bieżącego stanu ze sklepu
                            target_qty = c_qty if c_qty >= 0 else v_stock_map.get(vid, 0)

                        was_updated = update_stock_available(
                            client=client,
                            stock_record_id=s_id,
                            product_id=ps_product_id,
                            product_attribute_id=comb_id,
                            target_quantity=target_qty,
                            current_quantity=c_qty,
                            current_out_of_stock=c_out,
                            current_depends_on_stock=c_dos,
                        )
                        if was_updated:
                            stats.stocks_updated += 1
                        else:
                            stats.stocks_skipped += 1

        except Exception as exc:
            p_ref = getattr(p, "reference", getattr(p, "product_id", f"pozycja #{idx}"))
            p_name = getattr(p, "name", "brak nazwy")
            err_msg = f"Błąd przetwarzania produktu {p_ref} ('{p_name}'): {exc}"
            logger.error(err_msg)
            stats.errors += 1
            stats.error_details.append(err_msg)
            continue

    # 5. Atomowy zapis zaktualizowanego mapowania produktów z maską uprawnień 0664
    save_product_mapping(prod_map_path, product_mapping)

    logger.info(
        "Zakończono import produktów: utworzono %d, zaktualizowano %d, pominięto %d, błędy: %d.",
        stats.created,
        stats.updated,
        stats.skipped,
        stats.errors,
    )
    return stats, product_mapping
