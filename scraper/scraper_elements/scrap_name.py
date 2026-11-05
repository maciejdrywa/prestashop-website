from bs4 import BeautifulSoup

def scrap_name(soup: BeautifulSoup) -> str:
    name_tag = soup.find('h1')
    if name_tag:
        return name_tag.text.strip()
        # print(product_name)

    else:
        raise ValueError("Could not find product name")