from bs4 import BeautifulSoup

def scrap_name(soup: BeautifulSoup) -> str:
    """Odczytuje nazwę produktu ze źródłowego nagłówka."""
    name_tag = soup.find('h1')
    if name_tag:
        return name_tag.text.strip()

    else:
        raise ValueError("Could not find product name")