from bs4 import BeautifulSoup
import logging
import random
from Product import Product

logging.basicConfig(level=logging.ERROR, format = '%(levelname)s: %(message)s')

def parse_product(html_content: str, source_url: str) -> Product | None:
    soup = BeautifulSoup(html_content, 'html.parser')

    # Taking ID from body
    try:
        product_id = None

        body_tag = soup.find('body')

        body_classes = body_tag.get('class' ,[])

        for class_ in body_classes:
            if class_.startswith('product-id-'):
                product_id_taken = class_.replace('product-id-' , '')
                # print(product_id_taken)

                if product_id_taken.isdigit():
                    product_id = product_id_taken
                    break

        if not product_id:
            raise ValueError("Could not find product id")

        # Taking name
        product_name = None
        name_tag = soup.find('h1')
        if name_tag:
            product_name = name_tag.text.strip()
            # print(product_name)

        if not product_name:
            raise ValueError("Could not find product name")

        # Taking weight
        weight = None
        weight_tag = soup.find('meta', property='product:weight:value')
        if weight_tag and 'content' in weight_tag.attrs:
            try:
                weight = float(weight_tag['content'])
                print(weight)
            except ValueError:
                weight = None


        if not weight:
            raise ValueError("Could not find product weight")


        #Price brutto

        price_brutto = None
        price_brutto_tag = soup.find('meta', property='product:pretax_price:amount')
        if price_brutto_tag and 'content' in price_brutto_tag.attrs:
            try:
                price_brutto = float(price_brutto_tag['content'])
            except ValueError:
                price_brutto = None

        print(price_brutto)
        if not price_brutto:
            raise ValueError("Could not find price_brutto")

        #Price netto

        price_netto = None
        price_netto_tag = soup.find('meta', property='product:price:amount')

        if price_netto_tag and 'content' in price_netto_tag.attrs:
            try:
                price_netto = float(price_netto_tag['content'])
            except ValueError:
                price_netto = None

        print(price_netto)
        if not price_netto:
            raise ValueError("Could not find price_netto")


        vat_rate = float(round((price_brutto / price_netto - 1) * 100))

        # Images

        images = []

        images_tag = soup.find('ul', class_='product-images js-qv-product-images')

        if images_tag:
            images_all = images_tag.find_all('img')
            for image in images_all:
                images.append(image['data-image-large-src'])

        #description

        description_tag = soup.find('div', class_='product-description')
        if description_tag:
            description = description_tag.decode_contents().strip() # that extracts all contents of div

        #Catalog
        breadcrumbs = []

        nav_tag = soup.find('nav', class_='breadcrumb hidden-sm-down')
        if nav_tag:
            for li in nav_tag.find_all('li'):
                span_tag = li.find('span')
                if span_tag:
                    text_only = span_tag.text.strip()
                    breadcrumbs.append(text_only)


        #variants

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

            print(variants)
        return Product(
            product_id=product_id,
            product_url=source_url,
            name=product_name,
            description=description,
            breadcrumbs=breadcrumbs,
            price_brutto=price_brutto,
            price_netto=price_netto,
            vat_rate=vat_rate,
            weight=weight,
            images=images,
            variants=variants
        )
    except Exception as e:
        logging.error(f"Niepoprawny rekord: {source_url} | Przyczyna: {e}")
        return None