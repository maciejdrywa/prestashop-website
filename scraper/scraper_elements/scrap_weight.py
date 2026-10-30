from bs4 import BeautifulSoup
from typing import Optional

def scrap_weight(soup: BeautifulSoup) -> float | None:
    weight_tag = soup.find('meta', property='product:weight:value')
    if weight_tag and 'content' in weight_tag.attrs:
        try:
            return float(weight_tag['content'])
        except(ValueError, TypeError):
            return None