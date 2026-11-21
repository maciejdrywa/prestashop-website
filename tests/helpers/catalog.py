"""Odnajdywanie produktów scenariuszy niezależnie od ID nadanego przez instalację."""

from urllib.parse import urlencode, urljoin

from playwright.sync_api import Page, expect


def open_source_product(page: Page, source_id: int, query: str) -> str:
    """Wyszukuje produkt i sprawdza referencję; zwraca rzeczywiste ID sklepu."""
    reference = f"pandomu-{source_id}"
    search_url = "/szukaj?" + urlencode({"s": query})
    visited = set()
    while search_url and search_url not in visited:
        visited.add(search_url)
        page.goto(search_url, wait_until="domcontentloaded")
        expect(page.locator("body#search")).to_be_attached()
        candidates = page.locator("#js-product-list .product-title a").evaluate_all(
            "links => links.map(link => link.href)"
        )
        next_link = page.locator(".pagination a.next").first
        href = next_link.get_attribute("href") if next_link.count() else None
        search_url = urljoin(page.url, href) if href else None
        for url in dict.fromkeys(candidates):
            page.goto(url, wait_until="domcontentloaded")
            expect(page.locator("body#product")).to_be_attached()
            displayed = page.locator(".product-reference span").first
            actual = displayed.inner_text().strip() if displayed.count() else ""
            if actual == reference or actual.startswith(reference + "-combination-"):
                return page.locator('#add-to-cart-or-refresh input[name="id_product"]').input_value()
    raise AssertionError(f"Missing scenario product {reference} in search results for {query!r}")
