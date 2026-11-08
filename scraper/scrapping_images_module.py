import json
import logging
from pathlib import Path
import time

from playwright.sync_api import sync_playwright

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

from scrapping_images.create_zip_archive import create_zip_archive
from scrapping_images.process_and_save_images import process_and_save_images


BASE_DIR = Path(__file__).parent.parent
RESULTS_FILE = BASE_DIR / 'scraper-results' / 'products.json'
IMAGES_DIR = BASE_DIR / 'scraper'  / 'images'
SAMPLES_DIR = BASE_DIR / 'scraper-results' / 'samples'
ZIP_FILE = BASE_DIR / 'scraper-results' / 'product_images.zip'


def get_filename(url): # wyciagamy nazwe, jak sie da to bardziej unikalna, jak nie to nie, ale zwykle powinno sie dac
    parts = url.split('/')
    if len(parts) >= 2:
        return f"{parts[-2]}_{parts[-1]}"
    return parts[-1]


def main():
    if not RESULTS_FILE.exists():
        logging.error("Nie ma pliku z produktami")
        return

    with open(RESULTS_FILE, "r", encoding="utf-8") as f:
        products = json.load(f)


    # petla pobierania urlow zdjec

    image_urls = []
    for product in products:
        for url in product.get("images", []):
            if url not in image_urls:
                image_urls.append(url)

    logging.info(f"Petla Urlow zdjec skonczona. Znaleziono {len(image_urls)} dla {len(products)} produktow")

    IMAGES_DIR.mkdir(parents=True, exist_ok=True)
    SAMPLES_DIR.mkdir(parents=True, exist_ok=True)


    # Cookiesy do uzupelnienia inaczej nie bedzie dzialac

    cookies = ""

    with sync_playwright() as playwright:
        context = playwright.chromium.launch_persistent_context(
            user_data_dir=str(Path(__file__).parent / ".browser_profile"),
            headless=False,
            user_agent="Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:154.0) Gecko/20100101 Firefox/154.0",
            locale="pl-PL"
        )
        context.add_cookies([{
            "name": "cf_clearance",
            "value": cookies,
            "domain": ".pandomu.com.pl",
            "path": "/"
        }])

        page = context.pages[0] if context.pages else context.new_page()

        for img_url in image_urls:
            filename = get_filename(img_url)
            target_dir = IMAGES_DIR / filename

            if target_dir.exists():
                logging.info(f"Pomijam {filename}")
                continue

            logging.info(f"Pobieram {filename}")

            try:
                response = page.goto(img_url, wait_until="load", timeout=15000)
                if response and response.status == 200:
                    image_bytes = response.body()
                    save = process_and_save_images(image_bytes, target_dir)

                    current_samples = list(SAMPLES_DIR.glob("*.jpg"))
                    if save and len(current_samples) < 20:
                        target_dir_samples = SAMPLES_DIR / filename
                        process_and_save_images(image_bytes, target_dir_samples)

                else:
                    logging.error(f"Nie udalo sie pobrac {filename}")

            except Exception as e:
                logging.error(f"Nie udalo sie pobrac {filename}")

            time.sleep(1)

        context.close()

        create_zip_archive(IMAGES_DIR, ZIP_FILE)
        logging.info("ZIP gotowy")


if __name__ == "__main__":
    main()


