"""Traverse category trees and retain product membership evidence."""

from collections import deque
import logging
import re
from urllib.parse import urlsplit

from Scrapping_products.parse_category import parse_category, parse_navigation
from parser_classes.category_page import CategoryLink


def product_id_from_url(url: str) -> str:
    match = re.search(r"/(\d+)(?:-[^/]*)?\.html$", urlsplit(url).path)
    if match is None:
        raise ValueError(f"Nie można odczytać ID produktu z {url}")
    return match.group(1)


def scan_category(fetch_page, category_url: str) -> dict:
    """Scan all pages of one category, failing instead of returning partial data."""
    products = {}
    children = {}
    visited = set()
    current = category_url
    expected = None
    while current:
        if current in visited:
            raise RuntimeError(f"Pętla paginacji kategorii: {current}")
        visited.add(current)
        html = fetch_page(current)
        if not html:
            raise RuntimeError(f"Nie udało się pobrać kategorii: {current}")
        page = parse_category(html, current)
        if page is None:
            raise RuntimeError(f"Nie udało się sparsować kategorii: {current}")
        if page.total_products is not None:
            if expected is not None and expected != page.total_products:
                raise RuntimeError(f"Liczba produktów zmieniła się podczas pobierania: {category_url}")
            expected = page.total_products
        for url in page.product_urls:
            products.setdefault(product_id_from_url(url), url)
        for child in page.subcategories:
            children[child.url] = child
        current = page.next_page_url

    if expected is not None and len(products) != expected:
        raise RuntimeError(
            f"Niepełna kategoria {category_url}: znaleziono {len(products)}, oczekiwano {expected}."
        )
    return {
        "products": products,
        "children": list(children.values()),
        "pages_scanned": len(visited),
        "expected_products": expected,
    }


def discover_catalog(fetch_page, base_url: str = "https://pandomu.com.pl/") -> dict:
    html = fetch_page(base_url)
    if not html:
        raise RuntimeError("Nie udało się pobrać strony głównej.")
    menu_paths = parse_navigation(html, base_url)

    # Keep the existing source collection even if it is absent from the main menu.
    recommended = CategoryLink(name="Produkty polecane", url=base_url.rstrip("/") + "/81-produkty-polecane")
    if not any(path[-1].url == recommended.url for path in menu_paths):
        menu_paths.append([recommended])

    menu_children = {}
    roots = {}
    for path in menu_paths:
        roots.setdefault(path[0].url, path[0])
        for parent, child in zip(path, path[1:]):
            menu_children.setdefault(parent.url, {})[child.url] = child

    queue = deque([[root] for root in roots.values()])
    scanned = {}
    visited_paths = set()
    products = {}
    categories = []

    while queue:
        path = queue.popleft()
        urls = tuple(link.url for link in path)
        if urls in visited_paths:
            continue
        visited_paths.add(urls)
        category = path[-1]
        logging.info("Kategoria: %s", " > ".join(link.name for link in path))
        if category.url not in scanned:
            scanned[category.url] = scan_category(fetch_page, category.url)
        listing = scanned[category.url]
        names = [link.name for link in path]

        categories.append({
            "name": category.name,
            "category_path": names,
            "source_url": category.url,
            "parent_source_url": path[-2].url if len(path) > 1 else None,
            "product_ids": sorted(listing["products"]),
            "pages_scanned": listing["pages_scanned"],
            "expected_products": listing["expected_products"],
        })

        for product_id, product_url in listing["products"].items():
            entry = products.setdefault(product_id, {
                "product_id": product_id,
                "product_url": product_url,
                "memberships": [],
            })
            entry["memberships"].append({
                "category_path": names,
                "source_category_url": category.url,
            })

        children = dict(menu_children.get(category.url, {}))
        children.update({child.url: child for child in listing["children"]})
        for child in children.values():
            if child.url in urls:
                raise RuntimeError(f"Cykl drzewa kategorii: {child.url}")
            queue.append([*path, child])

    for entry in products.values():
        # Parent listings include descendant products. Keep the most specific
        # observed branches, not a separate redundant assignment to each parent.
        memberships = entry["memberships"]
        specific = [
            membership for membership in memberships
            if not any(
                len(other["category_path"]) > len(membership["category_path"])
                and other["category_path"][:len(membership["category_path"])] == membership["category_path"]
                for other in memberships
            )
        ]
        entry["memberships"] = sorted(
            specific,
            key=lambda item: (-len(item["category_path"]), item["category_path"], item["source_category_url"]),
        )
        entry["category_paths"] = [item["category_path"] for item in entry["memberships"]]
        entry["category_source_urls"] = [item["source_category_url"] for item in entry["memberships"]]

    if not products:
        raise RuntimeError("Nie znaleziono żadnych produktów; nie zapisano pustego katalogu.")
    return {"categories": categories, "products": products}
