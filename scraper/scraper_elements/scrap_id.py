from bs4 import BeautifulSoup

def scrap_id(soup: BeautifulSoup) -> str:
    body_tag = soup.find('body')

    body_classes = body_tag.get('class', [])

    for class_ in body_classes:
        if class_.startswith('product-id-'):
            product_id_taken = class_.replace('product-id-', '')

            if product_id_taken.isdigit():
                return product_id_taken

    raise ValueError("Could not find product id")

