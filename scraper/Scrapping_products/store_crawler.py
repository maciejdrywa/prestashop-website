import logging
import time
from pathlib import Path

from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeoutError

from Scrapping_products.catalog_discovery import discover_catalog, scan_category


class store_crawler:
    def __init__(self, cookies: str = "", *, headless: bool = False):

        self.playwright = sync_playwright().start()

        self.context = self.playwright.chromium.launch_persistent_context(
            user_data_dir=str(Path(__file__).resolve().parents[2] / ".browser_profile"),
            headless=headless,
            locale="pl-PL",
        )

        if cookies:
            self.context.add_cookies([
                {
                    "name": "cf_clearance",
                    "value": cookies,
                    "domain": ".pandomu.com.pl",
                    "path": "/"
                }
            ])

        self.page = self.context.pages[0] if self.context.pages else self.context.new_page() # albo uzywamy juz gotowej strony (karty) albo otwieramy nowa

        self.page.set_default_timeout(15000)


    def fetch_page(self, url:str) -> str | None:
        time.sleep(1)

        try:
            response = self.page.goto(url, wait_until="domcontentloaded", timeout=15000)

            if response is None:
                logging.error(f"Brak odpowiedzi dla {url}")
                return None

            if self.is_challenge():
                if not self.wait_for_cloudflare():
                    return None
                # Navigate again to check the actual requested page's status.
                response = self.page.goto(url, wait_until="domcontentloaded", timeout=15000)

            if response is None or not response.ok or self.is_challenge():
                logging.error("Nieudane pobranie %s: HTTP %s", url, response.status if response else "brak")
                return None

            logging.info(f"Status: {response}")

            html_content = self.page.content()
            return html_content

        except PlaywrightTimeoutError:
            logging.error(f"Timeout dla url:  {url}")
            return None
        except Exception as e:
            logging.error(f"Blad dla url: {url}, Exception: {e}")
            return None



    def discover_category(self, start_url: str) -> set:
        """Compatibility API: one category and its pagination, without descendants."""
        return set(scan_category(self.fetch_page, start_url)["products"].values())

    def discover_catalog(self) -> dict:
        return discover_catalog(self.fetch_page)

    def is_challenge(self) -> bool:
        title = self.page.title().casefold()
        return not title or any(text in title for text in ("just a moment", "cierpliwości", "cierpliwosci"))

    # To na wszelki wypadek jakby sie nie chcialo zalogowac przez ten cloudflare (!)

    def wait_for_cloudflare(self, max_wait = 100):
        start_time = time.time()

        while time.time() - start_time < max_wait:
            if self.is_challenge():
                logging.info("Czekam na weryfikacje")
                time.sleep(2)
            else:
                logging.info("Weryfikacja zakończona. Tytuł strony: %s", self.page.title())
                return True

        logging.warning("Nie udalo sie")
        return False

    def close(self):
        logging.info(f"Zamkniecie przegladarki")
        try:
            self.context.close()
        finally:
            self.playwright.stop()
