from bs4 import BeautifulSoup
import logging
from parser_classes.Product import Product
from scraper_elements.scrap_catalog import scrap_catalog
from scraper_elements.scrap_description import scrap_description

## Scraping devices
from scraper_elements.scrap_id import scrap_id
from scraper_elements.scrap_images import scrap_images
from scraper_elements.scrap_name import scrap_name
from scraper_elements.scrap_pretax_price import scrap_pretax_price
from scraper_elements.scrap_price import scrap_price
from scraper_elements.scrap_variants import scrap_variants
from scraper_elements.scrap_weight import scrap_weight


logging.basicConfig(level=logging.ERROR, format = '%(levelname)s: %(message)s')

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

        #description
        description = scrap_description(soup)

        #Catalog
        breadcrumbs = scrap_catalog(soup)

        #variants
        variants = scrap_variants(soup, product_name)

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