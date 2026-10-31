from bs4 import BeautifulSoup
from typing import List
import random
from parser_classes.ProductVariant import ProductVariant


def scrap_variants(soup: BeautifulSoup, product_name: str) -> List[ProductVariant]:
    variants = []

    variants_tag = soup.find('div', class_='product-variants js-product-variants')
    if not variants_tag:
        return variants

    items = variants_tag.find_all('div', class_='product-variants-item')

    for item in items:
        label_tag = item.find('span', class_='control-label')
        if label_tag:
            attribute_name = label_tag.get_text().split(':')[0].strip()
        else:
            attribute_name = 'Wariant'

        select_tags = item.find_all('select')
        for select in select_tags:
            attr_name = select.get('aria-label', attribute_name).strip() or attribute_name
            for option in select.find_all('option'):
                variant_id = option.get('value')
                variant_value = (option.get('title') or option.get_text()).strip()

                if variant_id and variant_value:
                    variants.append(ProductVariant(
                        variant_id=str(variant_id),
                        name=f"{product_name} - {variant_value}",
                        attributes={attr_name: variant_value},
                        price_modifier=0.0,
                        stock=random.randint(0, 10)
                    ))

        radio_inputs = item.find_all('input', class_='input-radio')
        for inp in radio_inputs:
            variant_id = inp.get('value')
            variant_value = inp.get('title', '').strip()
            if not variant_value:
                radio_span = inp.find_next_sibling('span', class_='radio-label')
                if radio_span:
                    variant_value = radio_span.get_text().strip()

            if variant_id and variant_value:
                variants.append(ProductVariant(
                    variant_id=str(variant_id),
                    name=f"{product_name} - {variant_value}",
                    attributes={attribute_name: variant_value},
                    price_modifier=0.0,
                    stock=random.randint(0, 10)
                ))

    return variants