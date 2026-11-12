import random

from bs4 import BeautifulSoup
import logging
from parser_classes.Product import Product
from scraper_elements.scrap_catalog import scrap_catalog
from scraper_elements.scrap_description import scrap_descriptions

## Scraping devices
from scraper_elements.scrap_id import scrap_id
from scraper_elements.scrap_images import scrap_images, scrap_cover_image
from scraper_elements.scrap_name import scrap_name
from scraper_elements.scrap_pretax_price import scrap_pretax_price
from scraper_elements.scrap_price import scrap_price
from scraper_elements.scrap_variants import scrap_variants
from scraper_elements.scrap_weight import scrap_weight

def parse_product(html_content: str, source_url: str) -> Product | None:
    soup = BeautifulSoup(html_content, 'html.parser')

    # Taking ID from body
    try:
        #Taking ID
        product_id = scrap_id(soup)

        # Taking name
        product_name = scrap_name(soup)

        # Taking weight
        weight = scrap_weight(soup)

        #Price brutto
        price_netto = scrap_pretax_price(soup)

        #Price netto
        price_brutto = scrap_price(soup)

        #Vat rate
        vat_rate = float(round((price_brutto / price_netto - 1) * 100))

        # Images
        images = scrap_images(soup)
        if not images:
            logging.warning("Produkt źródłowy nie ma zdjęć w galerii: %s", source_url)

        #description
        descriptions = scrap_descriptions(soup)

        #Catalog
        breadcrumbs = scrap_catalog(soup)

        #variants
        # HTML contains prices only for the selected combination. The crawler
        # resolves the remaining selections through source refresh responses.
        variants = scrap_variants(soup, product_name, price_netto)


        if len(variants) == 0:
            stock = random.randint(0,10)
        else:
            stock = None

        return Product(
            product_id=product_id,
            product_url=source_url,
            name=product_name,
            **descriptions,
            breadcrumbs=breadcrumbs,
            price_brutto=price_brutto,
            price_netto=price_netto,
            vat_rate=vat_rate,
            weight=weight,
            images=images,
            cover_image=scrap_cover_image(soup, images),
            variants=variants,
            stock= stock
        )
    except Exception as e:
        logging.error(f"Niepoprawny rekord: {source_url} | Przyczyna: {e}")
        return None
