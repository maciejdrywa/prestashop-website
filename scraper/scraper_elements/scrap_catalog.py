from bs4 import BeautifulSoup
from typing import List

def scrap_catalog(soup: BeautifulSoup) -> List[str]:
    breadcrumbs = []

    nav_tag = soup.find('nav', class_='breadcrumb hidden-sm-down')
    if nav_tag:
        for li in nav_tag.find_all('li'):
            span_tag = li.find('span')
            if span_tag:
                text_only = span_tag.text.strip()
                breadcrumbs.append(text_only)

    return breadcrumbs