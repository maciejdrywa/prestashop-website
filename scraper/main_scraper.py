import logging
import json
from pathlib import Path
import time

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)

from store_crawler import store_crawler
from parse_product import parse_product

def load_existing_products(filepath) -> list[dict]:
    if filepath.exists():
        try:
            with open(filepath, encoding="utf-8") as f:
                return json.load(f)
        except Exception as e:
            logging.error(f"Nie znalezniono pliku, {e}")
    return []

def save_products(filepath,products):
    filepath.parent.mkdir(parents=True, exist_ok=True)
    with open(filepath, "w", encoding="utf-8") as f:
        json.dump(products, f, ensure_ascii=False, indent=2)

RESULTS_DIR = Path(__file__).parent.parent / "scraper-results"
RESULTS_FILE = RESULTS_DIR / "products.json"

if __name__ == "__main__":

    # Zmieniac cookies na swoje bo nie bedzie dzialac
    cookies = ""
    crawler = store_crawler(cookies= cookies)

    categories =[
        "https://pandomu.com.pl/4-klamki-do-drzwi",
        "https://pandomu.com.pl/12-okucia-drzwiowe",
        "https://pandomu.com.pl/22-bramy-i-ogrodzenia",
        "https://pandomu.com.pl/33-okucia-budowlane",
        "https://pandomu.com.pl/36-dom-i-ogrod",
        "https://pandomu.com.pl/68-akcesoria-meblowe",
        "https://pandomu.com.pl/81-produkty-polecane"
    ]


    # Zbieranie Urlow
    all_product_urls = set()

    logging.info("start zbierania Url-ow")

    for category in categories:
        category_urls = crawler.discover_category(category)

        all_product_urls.update(category_urls)

    logging.info("koniec zbierania Url-ow")

    logging.info(f"znaleziono {len(all_product_urls)} urlow")

    saved_products = load_existing_products(RESULTS_FILE)

    scraped_products = {p["product_url"] for p in saved_products}

    logging.info(f"Wczesniej pobranych produktow bylo: {len(scraped_products)}")

    x = 0

    for url in all_product_urls:
        x += 1
        logging.info(f"Produkt {x} / {len(all_product_urls)}")
        if url in scraped_products:
            logging.info(f"Pomijam plik, bo jest juz pobrany {url}")
            continue

        logging.info(f"pobieram plik {url}")

        html_content = crawler.fetch_page(url)
        if not html_content:
            logging.warning("Nie znalezniono strony")
            continue

        product_obj = parse_product(html_content,url)

        if product_obj:
            product_dict = product_obj.model_dump()

            saved_products.append(product_dict)
            scraped_products.add(url)

            save_products(RESULTS_FILE, saved_products)
            logging.info(f"Pobrano plik {url}")
        else:
            logging.error(f"Blad {url}")

        time.sleep(1)

    logging.info("Koniec")
    crawler.close()