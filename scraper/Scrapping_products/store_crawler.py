import logging
import time

from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeoutError

from Scrapping_products.parse_category import parse_category


class store_crawler:
    def __init__(self, cookies: str):

        self.playwright = sync_playwright().start()

        self.context = self.playwright.chromium.launch_persistent_context(
            user_data_dir="../.browser_profile",
            headless=False, # To zmienic pozniej na True zeby caly czas nie zmienialy sie te karty na widoku
            user_agent= "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:154.0) Gecko/20100101 Firefox/154.0",
            locale="pl-PL",
        )

        self.context.add_cookies([
            {
                "name": "cf_clearance",
                "value": cookies,
                "domain": ".pandomu.com.pl",
                "path": "/"
            }
        ])

        self.page = self.context.pages[0] if self.context.pages else self.context.new_page() # albo uzywamy juz gotowej strony (karty) albo otwieramy nowa

        self.page.goto("https://pandomu.com.pl/", wait_until="domcontentloaded")

        self.page.set_default_timeout(15000)


    def fetch_page(self, url:str) -> str | None:
        time.sleep(1)

        try:
            response = self.page.goto(url, wait_until="domcontentloaded", timeout=15000)

            if response is None:
                logging.error(f"Brak odpowiedzi dla {url}")

            if "Just a moment" in self.page.title():
                self.wait_for_cloudflare()

            logging.info(f"Status: {response}")

            html_content = self.page.content()
            return html_content

        except PlaywrightTimeoutError:
            logging.error(f"Timeout dla url:  {url}")
            return None
        except Exception as e:
            logging.error(f"Blad dla url: {url}, Exception: {e}")
            return None



    def discover_category(self, start_url:str) ->set:
        discovered_product_urls = set()
        curr_url = start_url

        while curr_url:
            logging.info(f"URL: {curr_url} sprawdzany")

            html_content = self.fetch_page(curr_url)

            if not html_content:
                logging.warning(f"brak {curr_url}")
                break

            category_data = parse_category(html_content, curr_url)

            if category_data:
                for url in category_data.product_urls:
                    discovered_product_urls.add(url)

                    logging.info(f"Url produktu dodany. Url: {url}")

                curr_url =category_data.next_page_url

            else:
                logging.error("Brak kategorii")
                break
        return discovered_product_urls

    # To na wszelki wypadek jakby sie nie chcialo zalogowac przez ten cloudflare (!)

    def wait_for_cloudflare(self, max_wait = 100):
        start_time = time.time()

        while time.time() - start_time < max_wait:
            title = self.page.title()
            if "Just a moment" in title or "Cierpliwości" in title or "Cierpliwosci" in title or title == "":
                logging.info("Czekam na weryfikacje")
                time.sleep(2)
            else:
                logging.info(f"Cloudflare ograny. Tytuł strony: {title}")
                return True

        logging.warning("Nie udalo sie")
        return False

    def close(self):
        logging.info(f"Zamkniecie przegladarki")
        self.context.close()
        self.playwright.stop()