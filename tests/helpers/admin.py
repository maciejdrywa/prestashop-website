"""Panel administratora: potwierdzenie płatności, które wystawia fakturę VAT zamówienia."""

import os
import re
from dataclasses import dataclass, field
from pathlib import Path
from urllib.parse import parse_qs, urljoin, urlparse

from playwright.sync_api import Page, expect

ADMIN_PATH = os.environ.get("ADMIN_PATH", "/admin-dev/")
ENV_FILE = Path(__file__).resolve().parents[2] / ".env"


@dataclass(frozen=True)
class AdminCredentials:
    email: str
    password: str = field(repr=False)


def read_admin_credentials(env_file: Path = ENV_FILE) -> AdminCredentials:
    """Odczytuje konto z ADMIN_EMAIL i ADMIN_PASSWORD, a brakujące wartości z pliku .env repozytorium."""
    values = {name: os.environ.get(name, "") for name in ("ADMIN_EMAIL", "ADMIN_PASSWORD")}
    if not all(values.values()) and env_file.is_file():
        for line in env_file.read_text(encoding="utf-8").splitlines():
            name, separator, value = line.partition("=")
            name = name.strip().removeprefix("export ").strip()
            value = value.strip()
            if len(value) >= 2 and value[0] in "\"'" and value[0] in value[1:]:
                value = value[1 : value.index(value[0], 1)]
            else:
                # Jak Compose: komentarz po spacji kończy wartość bez cudzysłowów.
                value = re.split(r"\s+#", value, maxsplit=1)[0]
            if separator and name in values and not values[name]:
                values[name] = value
    missing = [name for name, value in values.items() if not value]
    assert not missing, f"Set {', '.join(missing)} (environment or .env) to confirm the payment in the back office"
    return AdminCredentials(values["ADMIN_EMAIL"], values["ADMIN_PASSWORD"])


def login_admin(page: Page, credentials: AdminCredentials) -> None:
    """Loguje administratora w osobnej sesji i czeka na menu panelu."""
    page.goto(ADMIN_PATH)
    form = page.locator("#login_form")
    expect(form, f"Admin login form not found at {ADMIN_PATH}").to_be_visible(timeout=15_000)
    form.locator("#email").fill(credentials.email)
    form.locator("#passwd").fill(credentials.password)
    form.locator("#submit_login").click()
    # Hasło z .env obowiązuje tylko przy pierwszej instalacji, dlatego komunikat wskazuje dane konta.
    expect(
        page.locator("#subtab-AdminOrders"), "Admin login failed: check ADMIN_EMAIL and ADMIN_PASSWORD"
    ).to_be_attached(timeout=20_000)


def open_admin_order(page: Page, order_id: str, reference: str) -> None:
    """Otwiera szczegóły zamówienia w panelu i sprawdza, że to zamówienie ze scenariusza."""
    href = page.locator("#subtab-AdminOrders > a").get_attribute("href")
    assert href, "Orders menu link has no URL"
    orders_url = urljoin(page.url, href)
    if "/sell/orders" not in orders_url:
        # Starszy adres kontrolera przekierowuje na trasę Symfony z tokenem.
        page.goto(orders_url)
        expect(page).to_have_url(re.compile(r"/sell/orders/?(\?|$)"), timeout=15_000)
        orders_url = page.url
    # Adresy panelu wymagają tokenu sesji; jest wspólny dla tras Symfony tego pracownika.
    tokens = parse_qs(urlparse(orders_url).query).get("_token", [])
    base = orders_url.split("/sell/orders", 1)[0]
    page.goto(f"{base}/sell/orders/{order_id}/view" + (f"?_token={tokens[0]}" if tokens else ""))
    expect(page.locator('[data-role="order-reference"]')).to_have_text(reference, timeout=15_000)


def change_order_status(page: Page, current_status: str, new_status: str, timeout: float = 20_000) -> None:
    """Zmienia status otwartego zamówienia i czeka na zapis w historii statusów."""
    select = page.locator("#update_order_status_action_input")
    button = page.locator("#update_order_status_action_btn")
    expect(select.locator("option:checked")).to_have_text(current_status)
    option = select.locator("option").filter(has_text=re.compile(rf"^\s*{re.escape(new_status)}\s*$"))
    expect(option, f'Order status "{new_status}" is not available').to_have_count(1)
    status_id = option.get_attribute("value")
    assert status_id, f'Order status "{new_status}" has no ID'
    select.select_option(value=status_id)
    expect(button).to_be_enabled()
    button.click()
    # Po zapisie panel przeładowuje stronę; atrybut przycisku wskazuje wtedy nowy bieżący status.
    expect(page.locator("#update_order_status_action_btn")).to_have_attribute(
        "data-order-status-id", status_id, timeout=timeout
    )
    expect(page.locator('[data-role="history-grid-table"]')).to_contain_text(new_status)


def assert_invoice_generated(page: Page) -> None:
    """Sprawdza w panelu, że zmiana statusu wystawiła fakturę zamówienia."""
    # Panel pokazuje przycisk faktury tylko przy włączonych fakturach i wystawionym numerze.
    expect(
        page.locator('[data-role="view-invoice"]'),
        "Invoice was not generated: check PS_INVOICE and the invoice flag of the status (configure-orders.php)",
    ).to_be_visible()
