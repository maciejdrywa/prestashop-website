from bs4 import BeautifulSoup
from typing import List
import random
from parser_classes.ProductVariant import ProductVariant

def scrap_variants(soup: BeautifulSoup, product_name: str) -> List[ProductVariant]:
    variants = []
    variants_tag = soup.find('div', class_='product-variants js-product-variants')

    if variants_tag:
        select_tags = variants_tag.find_all('select')

        for select in select_tags:
            attribute_name = select.get('aria-label', 'Wariant').strip()

            for option in select.find_all('option'):
                variant_id = option.get('value')
                variant_value = option.text.strip()

                variant_data = {
                    "variant_id": variant_id,
                    "name": f"{product_name} - {variant_value}",
                    "attributes": {
                        attribute_name: variant_value
                    },
                    "price_modifier": 0.0,
                    "stock": random.randint(0, 10)
                }

                variants.append(variant_data)
    return variants