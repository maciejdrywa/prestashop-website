from bs4 import BeautifulSoup


def scrap_descriptions(soup: BeautifulSoup) -> dict[str, str]:
    """Rozdziela krótką i pełną treść. Jedyny dostępny opis przypisuje do pełnego opisu."""
    short_node = soup.select_one('[id^="product-description-short-"]')
    full_node = soup.select_one("#description .product-description")
    if full_node is None:
        full_node = soup.select_one("#description")
    if full_node is None:
        full_node = soup.select_one(".product-description")

    def contents(node):
        """Zwraca wnętrze istniejącego elementu HTML albo pustą treść."""
        if node is None or not node.get_text(" ", strip=True).replace("\xa0", " ").strip():
            return ""
        return node.decode_contents().strip()

    short = contents(short_node)
    full = contents(full_node)
    if not full and not short:
        raise ValueError("Could not find description")
    if not full:
        return {"description": short, "description_short": ""}
    return {"description": full, "description_short": short}


def scrap_description(soup: BeautifulSoup) -> str:
    """Zwraca pełny opis produktu z zachowaniem dotychczasowego interfejsu parsera."""
    return scrap_descriptions(soup)["description"]
