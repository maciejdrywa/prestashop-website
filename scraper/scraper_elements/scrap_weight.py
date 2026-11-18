from bs4 import BeautifulSoup


def scrap_weight(soup: BeautifulSoup) -> float | None:
    """Odczytuje masę źródłową i przelicza ją na kg. Brak danych pozostawia jako None."""
    weight_tag = soup.find('meta', property='product:weight:value')
    if weight_tag and 'content' in weight_tag.attrs:
        try:
            return float(weight_tag['content'])
        except(ValueError, TypeError):
            return None