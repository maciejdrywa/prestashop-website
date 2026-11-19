"""Historia zamówień klienta: odszukanie zamówienia ze scenariusza, status i faktura VAT."""

import re
from decimal import Decimal
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import Locator, Page, TimeoutError, expect

from helpers.money import PLN_AMOUNT_PATTERN, parse_pln_amount

MY_ACCOUNT_URL = "/index.php?controller=my-account"
INVOICE_URL = "/index.php?controller=pdf-invoice&id_order={order_id}"
# Nazwy statusów zapisuje deploy/init/lib/orders.php we wszystkich językach sklepu.
STATUS_AWAITING_CASH_ON_DELIVERY = "Oczekiwanie na płatność przy odbiorze"
STATUS_PAYMENT_ACCEPTED = "Płatność zaakceptowana"
# Classic oznacza kolumnę faktury klasami, a nie atrybutem; kolumna płatności ma tylko hidden-md-down.
INVOICE_CELL = "td.text-sm-center.hidden-md-down"


def open_order_history(page: Page) -> None:
    """Otwiera historię zamówień z konta klienta i czeka na tabelę zamówień."""
    page.goto(MY_ACCOUNT_URL)
    expect(page.locator("body#my-account"), "Customer is not logged in").to_be_attached()
    page.locator("#history-link").click()
    expect(page.locator("body#history")).to_be_attached(timeout=15_000)
    expect(page.locator("table.table-labeled tbody tr").first, "Order history is empty").to_be_visible(timeout=15_000)


def get_order_row(page: Page, reference: str) -> Locator:
    """Zwraca jedyny wiersz historii z dokładnie tą referencją zamówienia."""
    reference_cell = page.locator("th[scope=row]", has_text=re.compile(rf"^\s*{re.escape(reference)}\s*$"))
    row = page.locator("table.table-labeled tbody tr").filter(has=reference_cell)
    # Jedna referencja może mieć kilka zamówień po podziale koszyka; scenariusz oczekuje jednego.
    expect(row, f"Order {reference} must appear exactly once in the order history").to_have_count(1)
    return row


def get_order_id(row: Locator) -> str:
    """Odczytuje ID zamówienia z odnośnika do szczegółów w wierszu historii."""
    href = row.locator("a.view-order-details-link").get_attribute("href")
    assert href, "Order details link has no URL"
    ids = parse_qs(urlparse(href).query).get("id_order", [])
    assert len(ids) == 1 and ids[0].isdigit(), f"Order ID not found in the details link: {href}"
    return ids[0]


def assert_order_total(row: Locator, expected_total: Decimal) -> None:
    """Porównuje kwotę zamówienia w historii z kwotą zapamiętaną przed zakupem."""
    total = row.locator("td.text-xs-right")
    expect(total).to_have_text(PLN_AMOUNT_PATTERN)
    actual_total = parse_pln_amount(total.inner_text())
    assert actual_total == expected_total, (
        f"Order total in history differs from checkout: {actual_total:.2f} PLN != {expected_total:.2f} PLN"
    )


def assert_order_status(row: Locator, status: str) -> None:
    """Sprawdza bieżący status zamówienia widoczny dla klienta."""
    expect(row.locator(".label-pill")).to_have_text(status)


def assert_invoice_not_listed(row: Locator) -> None:
    """Sprawdza, że historia nie udostępnia jeszcze faktury dla zamówienia."""
    cell = row.locator(INVOICE_CELL)
    expect(cell).to_have_text("-")
    expect(cell.locator("a")).to_have_count(0)


def assert_invoice_not_served(page: Page, order_id: str) -> None:
    """Sprawdza, że sklep nie zwraca PDF faktury przed potwierdzeniem płatności."""
    response = page.request.get(INVOICE_URL.format(order_id=order_id))
    # Kontroler kończy się komunikatem tekstowym; przekierowanie do logowania lub błąd serwera to inny problem.
    assert response.ok, f"Invoice check failed with HTTP {response.status}"
    assert "controller=authentication" not in response.url, "Customer session was lost before the invoice check"
    body = response.body()
    assert body.strip(), "Invoice check returned an empty response"
    assert b"%PDF" not in body, f"Invoice PDF of order {order_id} is served before payment acceptance"


def download_invoice(page: Page, row: Locator, timeout: float = 15_000) -> tuple[str, bytes]:
    """Pobiera fakturę z wiersza historii i zwraca nazwę oraz zawartość pliku."""
    link = row.locator(f"{INVOICE_CELL} a")
    expect(link, "Invoice link is missing in the order history").to_have_count(1)
    expect(link).to_be_visible()
    try:
        with page.expect_download(timeout=timeout) as download_info:
            link.click()
    except TimeoutError as error:
        # Kontroler faktury przy błędzie wyświetla tekst zamiast wysłać plik.
        try:
            response = page.locator("body").inner_text(timeout=2_000)[:300]
        except TimeoutError:
            response = "<no readable page>"
        raise AssertionError(f"Invoice was not downloaded, the shop responded: {response!r}") from error
    download = download_info.value
    failure = download.failure()
    assert failure is None, f"Invoice download failed: {failure}"
    return download.suggested_filename, Path(download.path()).read_bytes()
