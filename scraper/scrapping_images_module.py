import json
import logging
from pathlib import Path
import shutil
import time
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

from scrapping_images.create_zip_archive import create_zip_archive
from scrapping_images.process_and_save_images import image_meets_requirements, process_and_save_images


BASE_DIR = Path(__file__).parent.parent
RESULTS_FILE = BASE_DIR / 'scraper-results' / 'products.json'
IMAGES_DIR = BASE_DIR / 'scraper-results' / 'images'
SAMPLES_DIR = BASE_DIR / 'scraper-results' / 'samples'
ZIP_FILE = BASE_DIR / 'scraper-results' / 'product_images.zip'


def get_filename(url): # wyciagamy nazwe, jak sie da to bardziej unikalna, jak nie to nie, ale zwykle powinno sie dac
    parts = urlsplit(url).path.rstrip('/').split('/')
    if len(parts) >= 2:
        return str(Path(f"{parts[-2]}_{parts[-1]}").with_suffix(".jpg"))
    return str(Path(parts[-1]).with_suffix(".jpg"))


def save_sample(source: Path, samples_dir: Path | None = None):
    samples_dir = SAMPLES_DIR if samples_dir is None else samples_dir
    target = samples_dir / source.name
    if target.exists() and image_meets_requirements(target):
        return
    count = sum(1 for path in samples_dir.iterdir()
                if path.is_file() and path.suffix.lower() in {".jpg", ".jpeg", ".webp"})
    if target.exists() or count < 20:
        # Copy the validated JPEG without a second lossy compression.
        shutil.copyfile(source, target)


def main():
    if not RESULTS_FILE.exists():
        logging.error("Nie ma pliku z produktami")
        return 1

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
    for sample in SAMPLES_DIR.iterdir():
        if sample.is_file() and sample.suffix.lower() in {".jpg", ".jpeg"}:
            if not image_meets_requirements(sample) and not process_and_save_images(sample.read_bytes(), sample):
                logging.error("Niepoprawne zdjecie przykladowe: %s", sample.name)
                return 1
    failures = []


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
                if image_meets_requirements(target_dir) or process_and_save_images(target_dir.read_bytes(), target_dir):
                    save_sample(target_dir)
                    logging.info(f"Poprawny plik lokalny: {filename}")
                    continue
                logging.warning("Ponownie pobieram niepoprawny plik: %s", filename)

            logging.info(f"Pobieram {filename}")

            try:
                response = page.goto(img_url, wait_until="load", timeout=15000)
                if response and response.status == 200:
                    image_bytes = response.body()
                    save = process_and_save_images(image_bytes, target_dir)

                    if save:
                        save_sample(target_dir)
                    else:
                        failures.append(img_url)

                else:
                    logging.error(f"Nie udalo sie pobrac {filename}")
                    failures.append(img_url)

            except Exception as e:
                logging.error("Nie udalo sie pobrac %s: %s", filename, e)
                failures.append(img_url)

            time.sleep(1)

        context.close()

        if failures:
            logging.error("Nie utworzono archiwum: %s zdjec nie spelnia wymagan lub nie zostalo pobranych.", len(failures))
            return 1
        create_zip_archive(IMAGES_DIR, ZIP_FILE)
        logging.info("ZIP gotowy")
        return 0


if __name__ == "__main__":
    raise SystemExit(main())


