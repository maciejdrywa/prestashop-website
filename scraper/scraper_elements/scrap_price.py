from bs4 import BeautifulSoup

def scrap_price(soup: BeautifulSoup) -> float:
    price_netto_tag = soup.find('meta', property='product:price:amount')

    if price_netto_tag and 'content' in price_netto_tag.attrs:
        try:
            return float(price_netto_tag['content'])
        except ValueError:
            raise ValueError("Could not find price_netto")

