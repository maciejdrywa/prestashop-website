import logging
import time
from pathlib import Path

from bs4 import BeautifulSoup
from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from playwright.sync_api import sync_playwright
from Scrapping_products.catalog_discovery import discover_catalog, scan_category
from Scrapping_products.variant_prices import collect_variants


class StoreCrawler:
    """Obsługuje sesję Playwright oraz pobieranie katalogu i rzeczywistych kombinacji produktów."""
    def __init__(self, cookies: str = "", *, headless: bool = False):

        """Otwiera trwały profil Chromium z polską lokalizacją i opcjonalnym cookie sesji źródła."""
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

        self.page = self.context.pages[0] if self.context.pages else self.context.new_page() # Wykorzystujemy istniejącą kartę trwałego profilu lub otwieramy nową.

        self.page.set_default_timeout(15000)


    def fetch_page(self, url:str) -> str | None:
        """Pobiera HTML z kontrolą odpowiedzi i wyzwania Cloudflare. Nieudana próba zwraca None."""
        time.sleep(1)

        try:
            response = self.page.goto(url, wait_until="domcontentloaded", timeout=15000)

            if response is None:
                logging.error(f"Brak odpowiedzi dla {url}")
                return None

            if self.is_challenge():
                if not self.wait_for_cloudflare():
                    return None
                # Ponowna nawigacja potwierdza kod odpowiedzi żądanej strony po wyzwaniu.
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
        """Zwraca adresy produktów jednej kategorii wraz z jej stronami paginacji."""
        return set(scan_category(self.fetch_page, start_url)["products"].values())

    def discover_catalog(self) -> dict:
        """Przechodzi pełne drzewo źródła i zwraca kategorie oraz przypisania produktów."""
        return discover_catalog(self.fetch_page)

    def fetch_variants(self, html: str, product):
        """Weryfikuje ceny kombinacji przez żądania kontrolera produktu, zachowując sesję przeglądarki."""
        soup = BeautifulSoup(html, "html.parser")
        form = soup.select_one("#add-to-cart-or-refresh")

        def refresh_selection(selection):
            """Wysyła wybór atrybutów do kontrolera produktu i zwraca odpowiedź odświeżenia."""
            if form is None:
                raise ValueError("Brak formularza aktualizacji wariantu.")
            # Formularz wskazuje kontroler koszyka. Odświeżenie wariantu kierujemy do
            # kontrolera produktu, zgodnie z żądaniem wariantu w sklepie źródłowym.
            endpoint = product.product_url.split("#", 1)[0]
            fields = {
                node["name"]: node.get("value", "")
                for node in form.select('input[type="hidden"][name]')
                if node["name"] not in {"id_product_attribute", "add"}
            }
            fields.update({
                "id_product": str(product.product_id),
                "ajax": "1", "action": "refresh", "quantity_wanted": "1",
            })
            fields.update({f"group[{group}]": value for group, value in selection.items()})
            time.sleep(1)
            response = self.context.request.post(
                endpoint, form=fields, timeout=30000,
                headers={"X-Requested-With": "XMLHttpRequest", "Accept": "application/json"},
            )
            if not response.ok:
                raise RuntimeError(f"Błąd HTTP {response.status} przy pobieraniu wariantu {selection}.")
            try:
                return response.json()
            except Exception as error:
                raise ValueError("Źródło nie zwróciło JSON z ceną wariantu.") from error

        return collect_variants(html, product, refresh_selection)

    def is_challenge(self) -> bool:
        """Rozpoznaje stronę wyzwania Cloudflare zamiast oczekiwanej zawartości."""
        title = self.page.title().casefold()
        return not title or any(text in title for text in ("just a moment", "cierpliwości", "cierpliwosci"))

    # Ograniczone oczekiwanie umożliwia ręczne rozwiązanie wyzwania Cloudflare.

    def wait_for_cloudflare(self, max_wait = 100):
        """Czeka ograniczony czas na rozwiązanie wyzwania w bieżącej sesji."""
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
        """Zamyka kontekst przeglądarki i zatrzymuje instancję Playwright."""
        logging.info("Zamkniecie przegladarki")
        try:
            self.context.close()
        finally:
            self.playwright.stop()


# Zgodność ze starszymi importami narzędzi diagnostycznych.
store_crawler = StoreCrawler
