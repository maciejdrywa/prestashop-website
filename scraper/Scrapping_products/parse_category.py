"""Odczytuje kategorie z pominięciem odnośników filtrów i sortowania."""

import logging
import re
from urllib.parse import urljoin, urlsplit, urlunsplit

from bs4 import BeautifulSoup

from parser_classes.category_page import CategoryPage, CategoryLink


def store_url(href: str, base_url: str, *, pagination: bool = False) -> str | None:
    """Normalizuje wewnętrzny adres sklepu, opcjonalnie zachowując parametry paginacji."""
    parts = urlsplit(urljoin(base_url, href))
    if parts.scheme not in {"http", "https"} or parts.netloc != urlsplit(base_url).netloc:
        return None
    return urlunsplit((parts.scheme, parts.netloc, parts.path, parts.query if pagination else "", ""))


def link_name(anchor) -> str:
    # Pomijamy tekst ikon rozwijania i zwijania menu.
    """Odczytuje nazwę odnośnika z pominięciem tekstów zagnieżdżonych ikon menu."""
    return " ".join(" ".join(anchor.find_all(string=True, recursive=False)).split())


def parse_navigation(html: str, base_url: str) -> list[list[CategoryLink]]:
    """Odczytuje pełne ścieżki z menu kategorii. Brak drzewa zgłasza jako błąd."""
    soup = BeautifulSoup(html, "html.parser")
    menu = soup.select_one("#top-menu")
    if menu is None:
        raise ValueError("Nie znaleziono menu kategorii #top-menu.")
    paths = []
    for item in menu.select("li.category"):
        path = []
        current = item
        while current is not None:
            anchor = current.find("a", recursive=False)
            if anchor is None:
                raise ValueError("Kategoria w menu nie ma adresu.")
            url = store_url(anchor.get("href", ""), base_url)
            name = link_name(anchor)
            if not url or not name:
                raise ValueError("Niepoprawna kategoria w menu.")
            path.append(CategoryLink(name=name, url=url))
            current = current.find_parent("li", class_="category")
        paths.append(list(reversed(path)))
    if not paths:
        raise ValueError("Menu nie zawiera kategorii.")
    return paths


def parse_category(html_content: str, base_url: str) -> CategoryPage | None:
    """Parsuje listę produktów, podkategorie i następną stronę. Niepoprawny HTML zwraca jako None."""
    soup = BeautifulSoup(html_content, "html.parser")
    try:
        urls = []
        for anchor in soup.select("a.thumbnail.product-thumbnail"):
            url = store_url(anchor.get("href", ""), base_url)
            if url and url not in urls:
                urls.append(url)

        children = {}
        for item in soup.select("#subcategories li"):
            anchor = item.select_one("a.subcategory-name")
            if anchor is None:
                continue
            url = store_url(anchor.get("href", ""), base_url)
            image_link = item.select_one(".subcategory-image a[title]")
            # Etykieta może być ucięta, pełna nazwa znajduje się w tytule odnośnika.
            name = (image_link.get("title") if image_link else None) or anchor.get_text(" ", strip=True)
            if not url or not name.strip():
                raise ValueError("Niepoprawny link podkategorii.")
            children[url] = CategoryLink(name=" ".join(name.split()), url=url)

        next_link = soup.select_one('a[rel~="next"]')
        next_url = None
        if next_link is not None and "disabled" not in next_link.get("class", []):
            if not next_link.find_parent(class_="disabled"):
                next_url = store_url(next_link.get("href", ""), base_url, pagination=True)
                if not next_url:
                    raise ValueError("Niepoprawny adres następnej strony.")

        total = None
        total_tag = soup.select_one(".total-products")
        if total_tag:
            match = re.search(r"\d[\d\s\u00a0]*", total_tag.get_text(" ", strip=True))
            if match:
                total = int(re.sub(r"\s", "", match.group()))

        if not urls and total is None and soup.select_one("#products, #subcategories") is None:
            raise ValueError("Strona nie zawiera rozpoznawalnego katalogu produktów.")

        return CategoryPage(
            product_urls=urls,
            next_page_url=next_url,
            subcategories=list(children.values()),
            total_products=total,
        )
    except Exception:
        logging.exception("Nie udało się odczytać kategorii %s", base_url)
        return None
