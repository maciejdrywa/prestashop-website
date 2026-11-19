from __future__ import annotations

import json
import logging
from pathlib import Path
from typing import Any, Dict, List, Optional, Set
from urllib.parse import urlsplit

from .models import CategoryItem

logger = logging.getLogger("category_import")


def validate_category_record(raw: Dict[str, Any], index: int) -> CategoryItem:
    """
    Waliduje pojedynczy rekord z pliku categories.json i zwraca obiekt CategoryItem.
    Sprawdza obecność i poprawność pól 'name' oraz 'source_url' przed konwersją na tekst.
    Odrzuca brakujące, puste i null wartości (None, 'null', 'none').
    Rzuca ValueError w przypadku brakujących lub nieprawidłowych danych.
    """
    if not isinstance(raw, dict):
        raise ValueError(f"Rekord #{index} nie jest słownikiem: {raw}")

    # Sprawdzenie 'name' przed konwersją na tekst
    if "name" not in raw or raw["name"] is None:
        raise ValueError(f"Rekord #{index} nie posiada wymaganego pola 'name' (brak pola lub wartość null).")

    if not isinstance(raw["name"], str):
        raise ValueError(
            f"Rekord #{index} posiada pole 'name' niebędące łańcuchem znaków (typ: {type(raw['name']).__name__})."
        )

    name = raw["name"].strip()
    if not name or name.lower() in ("null", "none"):
        raise ValueError(f"Rekord #{index} posiada pustą wartość lub placeholder null w polu 'name'.")

    # Sprawdzenie 'source_url' przed konwersją na tekst
    if "source_url" not in raw or raw["source_url"] is None:
        raise ValueError(f"Rekord #{index} ('{name}') nie posiada wymaganego pola 'source_url' (brak pola lub wartość null).")

    if not isinstance(raw["source_url"], str):
        raise ValueError(
            f"Rekord #{index} ('{name}') posiada pole 'source_url' niebędące łańcuchem znaków (typ: {type(raw['source_url']).__name__})."
        )

    source_url = raw["source_url"].strip()
    if not source_url or source_url.lower() in ("null", "none"):
        raise ValueError(f"Rekord #{index} ('{name}') posiada pustą wartość w polu 'source_url'.")

    parsed = urlsplit(source_url)
    if parsed.scheme not in {"http", "https"} or not parsed.hostname or parsed.fragment or parsed.username or parsed.password:
        raise ValueError(f"Rekord #{index} posiada niepoprawny source_url.")

    # Sprawdzenie 'category_path'
    category_path = raw.get("category_path")
    if category_path is None or not isinstance(category_path, list) or not category_path:
        raise ValueError(f"Rekord #{index} ('{name}') posiada nieprawidłowe lub puste pole 'category_path': {category_path}")

    cleaned_path: List[str] = []
    for seg_idx, p in enumerate(category_path):
        if p is None:
            raise ValueError(f"Rekord #{index} ('{name}') posiada wartość null w 'category_path' na pozycji {seg_idx}.")
        if not isinstance(p, str):
            raise ValueError(f"Rekord #{index} ('{name}') posiada nie-tekstowy segment w 'category_path' na pozycji {seg_idx}: {p}")
        p_str = p.strip()
        if not p_str or p_str.lower() in ("null", "none"):
            raise ValueError(f"Rekord #{index} ('{name}') posiada pusty segment w 'category_path' na pozycji {seg_idx}.")
        cleaned_path.append(p_str)

    if not cleaned_path:
        raise ValueError(f"Rekord #{index} ('{name}') posiada pustą listę 'category_path'.")

    # Weryfikacja spójności: ostatni element ścieżki powinien odpowiadać nazwie kategorii
    if cleaned_path[-1] != name:
        raise ValueError(f"Rekord #{index}: nazwa kategorii różni się od ostatniego segmentu ścieżki.")

    parent_source_url_raw = raw.get("parent_source_url")
    if parent_source_url_raw is not None and not isinstance(parent_source_url_raw, str):
        raise ValueError(
            f"Rekord #{index} ('{name}') posiada nie-tekstowe pole 'parent_source_url': {type(parent_source_url_raw).__name__}"
        )
    parent_source_url = (
        parent_source_url_raw.strip()
        if parent_source_url_raw and parent_source_url_raw.strip().lower() not in ("null", "none")
        else None
    )

    # Kategorie pierwszego poziomu nie powinny mieć parent_source_url
    if len(cleaned_path) == 1 and parent_source_url is not None:
        logger.debug("Kategoria korzenia '%s' miała przypisany parent_source_url: %s", name, parent_source_url)

    # Podkategorie powinny wskazywać na rodzica
    if len(cleaned_path) > 1 and parent_source_url is None:
        logger.warning("Podkategoria '%s' (głębokość %d) nie ma podanego parent_source_url.", name, len(cleaned_path))

    product_ids_raw = raw.get("product_ids", [])
    product_ids = [str(pid) for pid in product_ids_raw] if isinstance(product_ids_raw, list) else []

    return CategoryItem(
        name=name,
        category_path=cleaned_path,
        source_url=source_url,
        parent_source_url=parent_source_url,
        product_ids=product_ids,
        raw_data=raw,
    )


def load_and_validate_categories(
    categories_path: Path,
    validation_errors: Optional[List[str]] = None,
) -> List[CategoryItem]:
    """
    Wczytuje i waliduje listę kategorii z pliku JSON.
    Odrzuca brakujące, puste i null wartości, a także duplikaty source_url.
    Rejestruje numer rekordu w validation_errors i kontynuuje wczytywanie pozostałych
    poprawnych, niezależnych pozycji.
    Sortuje kategorie rosnąco według długości category_path (rodzice przed dziećmi).
    """
    if not categories_path.is_file():
        raise FileNotFoundError(f"Plik kategorii nie istnieje: {categories_path}")

    with categories_path.open("r", encoding="utf-8") as f:
        try:
            data = json.load(f)
        except json.JSONDecodeError as exc:
            raise ValueError(f"Błąd składni JSON w pliku {categories_path}: {exc}") from exc

    if not isinstance(data, list):
        raise ValueError(f"Oczekiwano listy w pliku {categories_path}, otrzymano: {type(data).__name__}")

    items: List[CategoryItem] = []
    seen_source_urls: Set[str] = set()
    duplicate_urls: Set[str] = set()

    for idx, raw_record in enumerate(data):
        try:
            item = validate_category_record(raw_record, idx)
            # Wykrywanie duplikatów source_url w danych wejściowych
            if item.source_url in seen_source_urls:
                duplicate_urls.add(item.source_url)
                err_msg = (
                    f"Odrzucono niepoprawny rekord #{idx} ('{item.name}'): "
                    f"Rekord #{idx} posiada zduplikowany 'source_url' '{item.source_url}' "
                    f"(wystąpił już wcześniej w pliku źródłowym)."
                )
                logger.warning(err_msg)
                if validation_errors is not None:
                    validation_errors.append(err_msg)
                continue

            seen_source_urls.add(item.source_url)
            items.append(item)
        except ValueError as exc:
            err_msg = f"Odrzucono niepoprawny rekord #{idx}: {exc}"
            logger.warning(err_msg)
            if validation_errors is not None:
                validation_errors.append(err_msg)

    # Walidacja grafu po odczycie wszystkich rekordów: błędy blokują gałąź, nie cały plik.
    by_url = {item.source_url: item for item in items}
    paths = {}
    invalid = set(duplicate_urls)
    for item in items:
        key = tuple(segment.casefold() for segment in item.category_path)
        paths.setdefault(key, []).append(item.source_url)
    for urls in paths.values():
        if len(urls) > 1:
            invalid.update(urls)
    for item in items:
        parent = by_url.get(item.parent_source_url)
        if item.depth == 1:
            if item.parent_source_url is not None:
                invalid.add(item.source_url)
        elif parent is None or parent.category_path != item.category_path[:-1]:
            invalid.add(item.source_url)
    # Spójna ścieżka zmniejsza głębokość o jeden, co wyklucza cykle.
    changed = True
    while changed:
        changed = False
        for item in items:
            if item.source_url not in invalid and item.parent_source_url in invalid:
                invalid.add(item.source_url)
                changed = True
    for item in items:
        if item.source_url in invalid:
            message = f"Odrzucono kategorię '{item.name}': konflikt ścieżki, rodzica lub zależność od błędnej gałęzi."
            logger.warning(message)
            if validation_errors is not None:
                validation_errors.append(message)
    items = [item for item in items if item.source_url not in invalid]

    # Sortowanie hierarchiczne: najpierw poziom 1 (długość 1), potem 2, potem 3...
    items.sort(key=lambda c: (len(c.category_path), c.name))
    logger.info("Wczytano i zwalidowano %d poprawnych kategorii z pliku: %s", len(items), categories_path)
    return items
