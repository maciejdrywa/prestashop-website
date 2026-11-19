"""Kontrola historii zamówień, pobrania i walidacji faktury bez składania zamówień w sklepie."""

import threading
from decimal import Decimal
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import pytest
from helpers.admin import (
    assert_invoice_generated,
    change_order_status,
    open_admin_order,
    read_admin_credentials,
)
from helpers.checkout import Customer, confirm_order
from helpers.invoice import assert_invoice_pdf
from helpers.orders import (
    assert_invoice_not_listed,
    assert_invoice_not_served,
    assert_order_status,
    assert_order_total,
    download_invoice,
    get_order_id,
    get_order_row,
)
from playwright.sync_api import Page

REFERENCE = "ABCDEFGHI"


def make_pdf(text: str) -> bytes:
    """Buduje minimalny poprawny PDF z jedną stroną i podanym tekstem."""
    stream = f"BT /F1 12 Tf 72 720 Td ({text}) Tj ET".encode()
    objects = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R "
        b"/Resources << /Font << /F1 5 0 R >> >> >>",
        b"<< /Length %d >>\nstream\n%s\nendstream" % (len(stream), stream),
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ]
    content, offsets = b"%PDF-1.4\n", []
    for number, body in enumerate(objects, start=1):
        offsets.append(len(content))
        content += b"%d 0 obj\n%s\nendobj\n" % (number, body)
    xref = len(content)
    content += b"xref\n0 %d\n0000000000 65535 f \n" % (len(objects) + 1)
    content += b"".join(b"%010d 00000 n \n" % offset for offset in offsets)
    content += b"trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n" % (len(objects) + 1, xref)
    return content


def test_accept_invoice_pdf_with_order_reference():
    """Akceptuje czytelny PDF zawierający referencję zamówienia."""
    assert assert_invoice_pdf(make_pdf(f"Zamowienie {REFERENCE}"), REFERENCE) == 1


@pytest.mark.parametrize(
    ("content", "message"),
    [
        (b"", "Invoice file is empty"),
        (b"No invoice is available.", "Invoice is not a PDF file"),
        (b"<!DOCTYPE html><html><body>Logowanie</body></html>", "Invoice is not a PDF file"),
        (make_pdf(f"Zamowienie {REFERENCE}")[:-20], "Invoice PDF is truncated"),
        (b"%PDF-1.4\nnot really a pdf\n%%EOF\n", "Invoice PDF cannot be read|Invoice PDF has no pages"),
        (make_pdf(""), "Invoice PDF contains no text"),
        (make_pdf("Zamowienie ZZZZZZZZZ"), f"Invoice PDF does not mention order {REFERENCE}"),
    ],
    ids=["empty", "error-text", "html", "truncated", "corrupted", "no-text", "other-order"],
)
def test_reject_invalid_invoice(content: bytes, message: str):
    """Odrzuca pusty plik, odpowiedź tekstową, uszkodzony PDF i fakturę innego zamówienia."""
    with pytest.raises(AssertionError, match=message):
        assert_invoice_pdf(content, REFERENCE)


def history_html(invoice_cell: str, status: str = "Płatność zaakceptowana") -> str:
    """Odtwarza tabelę historii zamówień motywu Classic z dwoma zamówieniami klienta."""
    return f"""
        <body id="history"><table class="table table-striped table-bordered table-labeled hidden-sm-down"><tbody>
          <tr>
            <th scope="row">{REFERENCE}</th><td>19.11.2026</td><td class="text-xs-right">1 234,56 zł</td>
            <td class="hidden-md-down">Płatność przy odbiorze</td>
            <td><span class="label label-pill bright">{status}</span></td>
            <td class="text-sm-center hidden-md-down">{invoice_cell}</td>
            <td class="text-sm-center order-actions">
              <a class="view-order-details-link" href="https://shop.test/szczegoly-zamowienia?id_order=42">Szczegóły</a>
              <a class="reorder-link" href="https://shop.test/zamowienie?submitReorder=&id_order=42">Zamów ponownie</a>
            </td>
          </tr>
          <tr>
            <th scope="row">X{REFERENCE}</th><td>18.11.2026</td><td class="text-xs-right">10,00 zł</td>
            <td class="hidden-md-down">Przelew</td><td><span class="label label-pill">Anulowane</span></td>
            <td class="text-sm-center hidden-md-down">-</td>
            <td class="text-sm-center order-actions">
              <a class="view-order-details-link" href="https://shop.test/szczegoly-zamowienia?id_order=41">Szczegóły</a>
            </td>
          </tr>
        </tbody></table></body>
    """


INVOICE_LINK = '<a href="https://shop.test/index.php?controller=pdf-invoice&id_order=42"><i>E415</i></a>'


def test_find_order_status_and_total(page: Page):
    """Rozpoznaje zamówienie po pełnej referencji, a nie po fragmencie innej referencji."""
    page.set_content(history_html(INVOICE_LINK))
    row = get_order_row(page, REFERENCE)
    assert get_order_id(row) == "42"
    assert_order_status(row, "Płatność zaakceptowana")
    assert_order_total(row, Decimal("1234.56"))


def test_reject_missing_order_and_other_status(page: Page):
    """Kończy się błędem dla nieznanej referencji, innego statusu i innej kwoty."""
    page.set_content(history_html("-", status="Oczekiwanie na płatność przy odbiorze"))
    with pytest.raises(AssertionError, match="exactly once"):
        get_order_row(page, "QQQQQQQQQ")
    row = get_order_row(page, REFERENCE)
    with pytest.raises(AssertionError):
        assert_order_status(row, "Płatność zaakceptowana")
    with pytest.raises(AssertionError, match="Order total in history differs"):
        assert_order_total(row, Decimal("1234.55"))


def test_reject_split_order_with_same_reference(page: Page):
    """Kończy się błędem, gdy ta sama referencja dotyczy kilku zamówień."""
    html = history_html("-").replace(f"X{REFERENCE}", REFERENCE)
    page.set_content(html)
    with pytest.raises(AssertionError, match="exactly once"):
        get_order_row(page, REFERENCE)


def test_invoice_not_listed_before_payment(page: Page):
    """Rozróżnia brak faktury od dostępnego odnośnika."""
    page.set_content(history_html("-"))
    assert_invoice_not_listed(get_order_row(page, REFERENCE))
    page.set_content(history_html(INVOICE_LINK))
    with pytest.raises(AssertionError):
        assert_invoice_not_listed(get_order_row(page, REFERENCE))


def test_download_invoice(page: Page):
    """Pobiera plik wysłany jako załącznik i zwraca jego zawartość."""
    pdf = make_pdf(f"Faktura {REFERENCE}")
    page.route(
        "https://shop.test/index.php?controller=pdf-invoice*",
        lambda route: route.fulfill(
            body=pdf,
            content_type="application/pdf",
            headers={"Content-Disposition": 'attachment; filename="000001.pdf"'},
        ),
    )
    page.set_content(history_html(INVOICE_LINK))
    filename, content = download_invoice(page, get_order_row(page, REFERENCE))
    assert filename == "000001.pdf"
    assert content == pdf


def test_reject_invoice_error_page(page: Page):
    """Kończy się błędem, gdy sklep zamiast pliku wyświetla komunikat o braku faktury."""
    page.route(
        "https://shop.test/**",
        lambda route: route.fulfill(body="No invoice is available.", content_type="text/html"),
    )
    page.set_content(history_html(INVOICE_LINK))
    with pytest.raises(AssertionError, match="Invoice was not downloaded.*No invoice is available"):
        download_invoice(page, get_order_row(page, REFERENCE), timeout=2_000)


def test_reject_missing_invoice_link(page: Page):
    """Kończy się błędem, gdy historia nie udostępnia faktury."""
    page.set_content(history_html("-"))
    with pytest.raises(AssertionError, match="Invoice link is missing"):
        download_invoice(page, get_order_row(page, REFERENCE))


class InvoiceHandler(BaseHTTPRequestHandler):
    """Odpowiedzi kontrolera faktury wybierane przez test."""

    mode = "text"

    def do_GET(self):
        if self.mode == "login" and "authentication" not in self.path:
            self.send_response(302)
            self.send_header("Location", "/index.php?controller=authentication&back=pdf-invoice")
            self.end_headers()
            return
        status, body = {
            "text": (200, b"No invoice is available."),
            "login": (200, b"<html>Logowanie</html>"),
            "pdf": (200, make_pdf(f"Faktura {REFERENCE}")),
            "error": (500, b"Internal Server Error"),
            "empty": (200, b""),
        }[self.mode]
        self.send_response(status)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


@pytest.fixture
def invoice_server_page(browser):
    """Strona z adresem bazowym lokalnego serwera symulującego kontroler faktury."""
    server = ThreadingHTTPServer(("127.0.0.1", 0), InvoiceHandler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    context = browser.new_context(base_url=f"http://127.0.0.1:{server.server_address[1]}")
    yield context.new_page()
    context.close()
    server.shutdown()


def test_invoice_not_served_before_payment(invoice_server_page: Page):
    """Akceptuje komunikat sklepu o braku faktury."""
    InvoiceHandler.mode = "text"
    assert_invoice_not_served(invoice_server_page, "42")


@pytest.mark.parametrize(
    ("mode", "message"),
    [
        ("pdf", "served before payment acceptance"),
        ("login", "Customer session was lost"),
        ("error", "HTTP 500"),
        ("empty", "empty response"),
    ],
)
def test_reject_unexpected_invoice_response(invoice_server_page: Page, mode: str, message: str):
    """Nie uznaje za brak faktury pliku PDF, wylogowania, błędu serwera ani pustej odpowiedzi."""
    InvoiceHandler.mode = mode
    with pytest.raises(AssertionError, match=message):
        assert_invoice_not_served(invoice_server_page, "42")


def test_confirm_order_returns_reference(page: Page):
    """Zwraca samą referencję z potwierdzenia zamówienia."""
    page.set_content(f"""
        <body id="checkout"><div id="payment-confirmation"><button type="submit" onclick="
          document.body.id = 'order-confirmation';
          document.body.innerHTML = `<div id='content-hook_order_confirmation'>
            <h3>Twoje zamówienie zostało potwierdzone</h3><p>jan@example.com</p>
            <span id='order-reference-value'>Numer referencyjny zamówienia: {REFERENCE}</span></div>`;
        ">Złóż zamówienie</button></div></body>
    """)
    assert confirm_order(page, Customer("Jan", "Testowy", "jan@example.com")) == REFERENCE


AWAITING, ACCEPTED = "Oczekiwanie na płatność przy odbiorze", "Płatność zaakceptowana"


def admin_order_html(status_id: str, invoice: bool) -> str:
    """Odtwarza elementy widoku zamówienia w panelu PrestaShop 8.2 potrzebne do zmiany statusu."""
    options = "".join(
        f'<option value="{value}"{" selected" if value == status_id else ""}>{name}</option>'
        for value, name in [("10", AWAITING), ("2", ACCEPTED), ("6", "Anulowane")]
    )
    names = [ACCEPTED, AWAITING] if status_id == "2" else [AWAITING]
    history = "".join(f'<tr><td data-role="status-column">{name}</td></tr>' for name in names)
    return f"""
        <strong data-role="order-reference">{REFERENCE}</strong>
        <form method="post" action="/admin-dev/index.php/sell/orders/42/status?_token=TKN">
          <select id="update_order_status_action_input" name="status">{options}</select>
          <button id="update_order_status_action_btn" disabled data-order-status-id="{status_id}">Aktualizuj</button>
        </form>
        <table data-role="history-grid-table"><tbody>{history}</tbody></table>
        {'<a data-role="view-invoice" href="/invoice">Faktura</a>' if invoice else ""}
        <script>
          const button = document.querySelector('#update_order_status_action_btn');
          document.querySelector('#update_order_status_action_input').addEventListener('change', (event) => {{
            button.disabled = Number(event.target.value) === Number(button.dataset.orderStatusId);
          }});
        </script>
    """


@pytest.fixture
def admin_shop(page: Page):
    """Symuluje menu, widok zamówienia i zapis statusu panelu pod adresem shop.test."""
    requests, state = [], {"saved": True}

    def handle(route):
        request = route.request
        requests.append((request.method, request.url))
        if "controller=AdminDashboard" in request.url:
            body = '<li id="subtab-AdminOrders"><a href="/admin-dev/index.php/sell/orders/?_token=TKN">Zamówienia</a></li>'
        elif request.method == "POST":
            body = admin_order_html("2" if state["saved"] else "10", invoice=state["saved"])
        else:
            body = admin_order_html("10", invoice=False)
        route.fulfill(body=body, content_type="text/html; charset=utf-8")

    page.route("https://shop.test/**", handle)
    page.goto("https://shop.test/admin-dev/index.php?controller=AdminDashboard&token=legacy")
    return page, requests, state


def test_change_order_status_in_back_office(admin_shop):
    """Otwiera właściwe zamówienie z tokenem z menu, zapisuje status i wykrywa wystawioną fakturę."""
    page, requests, _ = admin_shop
    open_admin_order(page, "42", REFERENCE)
    assert requests[-1] == ("GET", "https://shop.test/admin-dev/index.php/sell/orders/42/view?_token=TKN")
    change_order_status(page, AWAITING, ACCEPTED)
    assert requests[-1][0] == "POST"
    assert_invoice_generated(page)


def test_reject_unsaved_status_and_missing_invoice(admin_shop):
    """Kończy się błędem, gdy panel nie zapisał statusu lub nie wystawił faktury."""
    page, _, state = admin_shop
    state["saved"] = False
    open_admin_order(page, "42", REFERENCE)
    with pytest.raises(AssertionError):
        change_order_status(page, AWAITING, ACCEPTED, timeout=1_000)
    with pytest.raises(AssertionError, match="Invoice was not generated"):
        assert_invoice_generated(page)


def test_reject_other_order_in_back_office(admin_shop):
    """Kończy się błędem, gdy widok w panelu dotyczy innej referencji."""
    page, _, _ = admin_shop
    with pytest.raises(AssertionError):
        open_admin_order(page, "42", "ZZZZZZZZZ")


def test_read_admin_credentials(tmp_path, monkeypatch):
    """Zmienne środowiskowe mają pierwszeństwo, a brakujące dane uzupełnia plik .env."""
    env_file = tmp_path / ".env"
    env_file.write_text("# Konto\nADMIN_EMAIL=admin@example.test\nADMIN_PASSWORD='z-pliku'\n", encoding="utf-8")
    monkeypatch.setenv("ADMIN_EMAIL", "inny@example.test")
    monkeypatch.delenv("ADMIN_PASSWORD", raising=False)
    credentials = read_admin_credentials(env_file)
    assert (credentials.email, credentials.password) == ("inny@example.test", "z-pliku")
    assert "z-pliku" not in repr(credentials)


def test_read_admin_credentials_env_syntax(tmp_path, monkeypatch):
    """Obsługuje export, cudzysłowy z krzyżykiem i komentarz po wartości."""
    monkeypatch.delenv("ADMIN_EMAIL", raising=False)
    monkeypatch.delenv("ADMIN_PASSWORD", raising=False)
    env_file = tmp_path / ".env"
    env_file.write_text('export ADMIN_EMAIL=admin@example.test  # konto\nADMIN_PASSWORD="ab#c d"\n', encoding="utf-8")
    credentials = read_admin_credentials(env_file)
    assert (credentials.email, credentials.password) == ("admin@example.test", "ab#c d")


def test_reject_missing_admin_credentials(tmp_path, monkeypatch):
    """Kończy się błędem z nazwą brakującej zmiennej przed złożeniem zamówienia."""
    monkeypatch.delenv("ADMIN_EMAIL", raising=False)
    monkeypatch.delenv("ADMIN_PASSWORD", raising=False)
    (tmp_path / ".env").write_text("ADMIN_EMAIL=admin@example.test\nADMIN_PASSWORD=\n", encoding="utf-8")
    with pytest.raises(AssertionError, match="Set ADMIN_PASSWORD"):
        read_admin_credentials(tmp_path / ".env")
