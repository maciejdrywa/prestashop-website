from bs4 import BeautifulSoup

def scrap_description(soup: BeautifulSoup) -> str:
    description_tag = soup.find('div', class_='product-description')
    if description_tag:
        return description_tag.decode_contents().strip()  # that extracts all contents of div
    raise ValueError("Could not find description")