import logging

from bs4 import BeautifulSoup

from parser_classes.category_page import category_page


def parse_category(html_content: str, base_url: str) -> category_page | None:
    soup = BeautifulSoup(html_content, 'html.parser')

    try:
        urls = []

        url_tags = soup.find_all('a', class_='thumbnail product-thumbnail')

        for url in url_tags:
            if 'href' in url.attrs:
                urls.append(url['href'])

        next_url = None

        next_url_tag = soup.find('a', rel='next js-search-link')
        if next_url_tag and 'href' in next_url_tag.attrs:
            next_url = next_url_tag['href']

        return category_page(
            product_urls=urls,
            next_page_url=next_url
        )
    except Exception as e:
        logging.error(f"Blad parsowania {e}")
        return None
