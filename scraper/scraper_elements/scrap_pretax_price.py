from bs4 import BeautifulSoup

def scrap_pretax_price(soup: BeautifulSoup) -> float:
    price_brutto_tag = soup.find('meta', property='product:pretax_price:amount')
    if price_brutto_tag and 'content' in price_brutto_tag.attrs:
        try:
            return float(price_brutto_tag['content'])
        except ValueError:
            raise ValueError("Could not find price_brutto")

