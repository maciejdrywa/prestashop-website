"""Parse only the combination actually represented by this HTML snapshot."""

from bs4 import BeautifulSoup

from parser_classes.ProductVariant import ProductVariant
from Scrapping_products.variant_prices import attribute_choices, read_product_data, variant_from_data


def scrap_variants(soup: BeautifulSoup, product_name: str, base_netto: float) -> list[ProductVariant]:
    if soup.select_one("[data-product]") is None:
        if attribute_choices(soup):
            raise ValueError("Formularz wariantów nie zawiera danych wybranej kombinacji.")
        return []
    data = read_product_data(soup)
    if str(data.get("id_product_attribute") or "0") == "0":
        if attribute_choices(soup):
            raise ValueError("Źródło nie potwierdziło wybranej kombinacji.")
        return []
    return [variant_from_data(data, product_name, base_netto)]
