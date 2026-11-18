"""Odczytuje rzeczywiste kombinacje PrestaShop i ich wyświetlane ceny."""

import json
import logging
import random
import re
from collections import deque
from decimal import ROUND_HALF_UP, Decimal, InvalidOperation
from itertools import product as cartesian_product

from bs4 import BeautifulSoup
from parser_classes.ProductVariant import ProductVariant


def decimal_price(value, field: str) -> Decimal:
    """Waliduje źródłową cenę i zwraca Decimal bez utraty precyzji."""
    try:
        if isinstance(value, bool):
            raise ValueError("Boolean is not a price")
        price = Decimal(str(value))
        if not price.is_finite() or price < 0:
            raise ValueError("Price must be finite and non-negative")
        return price
    except (InvalidOperation, ValueError) as error:
        raise ValueError(f"Niepoprawna cena {field}: {value!r}") from error


def read_product_data(html_or_soup) -> dict:
    """Odczytuje dane produktu z HTML albo obiektu BeautifulSoup."""
    soup = BeautifulSoup(html_or_soup, "html.parser") if isinstance(html_or_soup, str) else html_or_soup
    node = soup.select_one("#product-details[data-product]")
    if node is None:
        node = soup.select_one("[data-product]")
    if node is None:
        raise ValueError("Brak danych produktu data-product w odpowiedzi źródła.")
    data = json.loads(node["data-product"], parse_float=Decimal)
    if not isinstance(data, dict):
        raise ValueError("Niepoprawne dane produktu w odpowiedzi źródła.")
    return data


def selected_attributes(data: dict) -> dict[str, str]:
    """Zwraca mapę wybranych grup i wartości z danych kombinacji."""
    attributes = data.get("attributes") or {}
    if not isinstance(attributes, dict):
        raise ValueError("Niepoprawne atrybuty kombinacji.")
    selected = {}
    for group_id, attribute in attributes.items():
        if not isinstance(attribute, dict) or not attribute.get("id_attribute"):
            raise ValueError("Brak ID atrybutu kombinacji.")
        selected[str(group_id)] = str(attribute["id_attribute"])
    return selected


def attribute_choices(html_or_soup) -> dict[str, set[str]]:
    """Zbiera dostępne opcje aktualnego formularza produktu."""
    soup = BeautifulSoup(html_or_soup, "html.parser") if isinstance(html_or_soup, str) else html_or_soup
    choices = {}
    for control in soup.select('.product-variants select[name], .product-variants input[type="radio"][name]'):
        match = re.fullmatch(r"group\[(\d+)\]", control.get("name", ""))
        if not match:
            continue
        values = [option.get("value") for option in control.select("option")] if control.name == "select" else [control.get("value")]
        for value in values:
            if value and str(value).isdigit():
                choices.setdefault(match.group(1), set()).add(str(value))
    return choices


def variant_from_data(data: dict, product_name: str, base_netto) -> ProductVariant:
    """Buduje kombinację z odpowiedzi źródła i różnicą netto względem ceny bazowej."""
    variant_id = str(data.get("id_product_attribute") or "")
    if not variant_id.isdigit() or int(variant_id) <= 0:
        raise ValueError("Źródło nie zwróciło ID rzeczywistej kombinacji.")
    selected = selected_attributes(data)
    if not selected:
        raise ValueError("Kombinacja nie ma przypisanych atrybutów.")
    attributes = {}
    for group_id in sorted(selected, key=int):
        attribute = data["attributes"][group_id]
        group = str(attribute.get("group", "")).strip()
        name = str(attribute.get("name", "")).strip()
        if not group or not name or group in attributes:
            raise ValueError("Brak lub powtórzona nazwa grupy atrybutów.")
        attributes[group] = name
    net = decimal_price(data.get("price_tax_exc"), "price_tax_exc")
    gross = decimal_price(data.get("price_amount"), "price_amount")
    modifier = (net - decimal_price(base_netto, "base_netto")).quantize(Decimal("0.000001"), rounding=ROUND_HALF_UP)
    return ProductVariant(
        variant_id=variant_id,
        name=product_name + " - " + ", ".join(attributes.values()),
        attributes=attributes,
        price_modifier=float(modifier),
        price_netto=float(net),
        price_brutto=float(gross),
        stock=random.randint(0, 10),
    )


def collect_variants(html: str, source_product, refresh_selection) -> list[ProductVariant]:
    """Zwraca rzeczywiste kombinacje z cenami i sześciocyfrowym modyfikatorem netto.

    HTML udostępnia aktualne opcje, source_product cenę bazową, a callback
    refresh_selection odpowiedź kontrolera dla mapy wybranych atrybutów.
    Nieistniejące wybory są pomijane po rozpoznaniu odpowiedzi zastępczej.
    ValueError przerywa pobieranie przy niepotwierdzonym produkcie lub cenach.
    """
    choices = attribute_choices(html)
    if not choices:
        return source_product.variants
    initial = read_product_data(html)
    if str(initial.get("id_product", initial.get("id"))) != str(source_product.product_id):
        raise ValueError("Odpowiedź wariantów dotyczy innego produktu.")
    initial_selection = selected_attributes(initial)
    # Niektóre adresy wskazują starą kombinację, której atrybuty różnią się
    # od aktualnego formularza, np. produkt 631 ze starym rozmiarem i aktualnymi kolorami.
    # Każdy obecny wybór należy wtedy potwierdzić przez kontroler źródła.
    if not set(choices) <= set(initial_selection):
        logging.warning(
            "Domyślna kombinacja ma grupy %s, a formularz %s. "
            "Ceny zostaną odczytane dla aktualnych opcji formularza.",
            sorted(initial_selection), sorted(choices),
        )
    for group in choices.keys() & initial_selection.keys():
        choices[group].add(initial_selection[group])
    variants = {}
    variant_selections = {}
    selection_ids = {}
    pending = deque()
    queued = set()

    def enqueue_choices():
        """Dodaje nieodwiedzone wybory atrybutów do kolejki odświeżania kombinacji."""
        groups = sorted(choices, key=int)
        for values in cartesian_product(*(sorted(choices[group], key=int) for group in groups)):
            selection = tuple(zip(groups, values))
            if selection not in queued:
                queued.add(selection)
                pending.append(selection)

    enqueue_choices()
    while pending:
        selection = pending.popleft()
        requested = dict(selection)
        if all(initial_selection.get(group) == value for group, value in requested.items()):
            data = initial
        else:
            payload = refresh_selection(requested)
            if not isinstance(payload, dict) or payload.get("hasError") or payload.get("errors"):
                raise ValueError("Źródło zgłosiło błąd pobierania kombinacji.")
            data = read_product_data(payload.get("product_details", ""))
            if str(data.get("id_product", data.get("id"))) != str(source_product.product_id):
                raise ValueError("Odpowiedź kombinacji dotyczy innego produktu.")
            new_choices = attribute_choices(payload.get("product_variants", ""))
            if new_choices and set(new_choices) != set(choices):
                raise ValueError("Odpowiedź zmieniła zestaw grup atrybutów.")
            expanded = False
            for group, values in new_choices.items():
                if not values <= choices[group]:
                    choices[group].update(values)
                    expanded = True
            if expanded:
                enqueue_choices()
        actual = selected_attributes(data)
        # Dodatkowe atrybuty mogą należeć do stałych grup niewidocznych w formularzu.
        # Każdy żądany atrybut musi mieć dokładne potwierdzenie w odpowiedzi.
        if not all(actual.get(group) == value for group, value in requested.items()):
            logging.info("Źródło nie potwierdziło kombinacji %s; pominięto zastępczy wybór %s", requested, actual)
            continue
        variant = variant_from_data(data, source_product.name, source_product.price_netto)
        if variant.variant_id in variant_selections and variant_selections[variant.variant_id] != selection:
            raise ValueError("Jedno źródłowe ID kombinacji wskazuje różne zestawy atrybutów.")
        if selection in selection_ids and selection_ids[selection] != variant.variant_id:
            raise ValueError("Źródło zwróciło różne ID tej samej kombinacji.")
        variant_selections[variant.variant_id] = selection
        selection_ids[selection] = variant.variant_id
        variants[variant.variant_id] = variant
        logging.info("Kombinacja %s: brutto %s, modyfikator netto %s", variant.variant_id, variant.price_brutto, variant.price_modifier)
    if not variants:
        raise ValueError("Nie udało się potwierdzić żadnej kombinacji produktu.")
    return sorted(variants.values(), key=lambda variant: int(variant.variant_id))
