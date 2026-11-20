"""Konfiguracja sesji E2E, niezależna od testów scrapera offline."""

import os
import time
from pathlib import Path

import pytest
from playwright.sync_api import expect

# Domyślne limity; zmienne środowiskowe pozwalają je zmienić dla wolniejszego klastra.
DEFAULT_E2E_MAX_SECONDS = 300.0
DEFAULT_E2E_EXPECT_TIMEOUT_MS = 5000.0

# Hosty analityki blokowane w ruchu testowym, łącznie z ich subdomenami.
ANALYTICS_HOSTS = (
    "google-analytics.com",
    "analytics.google.com",
    "googletagmanager.com",
    "doubleclick.net",
)

_E2E_DIR = Path(__file__).parent / "e2e"
_MAX_SECONDS_KEY = pytest.StashKey[float]()
_ALLOW_ANALYTICS_KEY = pytest.StashKey[bool]()


def parse_positive_number(name: str, raw: str | None, default: float) -> float:
    """Odczytuje dodatnią liczbę ze zmiennej środowiskowej; pusta wartość oznacza domyślną."""
    if raw is None or not raw.strip():
        return default
    try:
        value = float(raw.strip())
    except ValueError:
        raise pytest.UsageError(f"{name} must be a positive number, got {raw!r}") from None
    # NaN i nieskończoność nie mają sensu jako limit czasu.
    if not value > 0 or value == float("inf"):
        raise pytest.UsageError(f"{name} must be a positive number, got {raw!r}")
    return value


def parse_flag(name: str, raw: str | None) -> bool:
    """Odczytuje przełącznik 0/1; literówka przerywa sesję zamiast po cichu zmieniać zachowanie."""
    value = (raw or "").strip().lower()
    if value in ("", "0", "false", "no"):
        return False
    if value in ("1", "true", "yes"):
        return True
    raise pytest.UsageError(f"{name} must be 0 or 1, got {raw!r}")


def analytics_resolver_rules() -> str:
    """Reguły Chromium, które kierują hosty analityki i ich subdomeny na nieistniejący adres."""
    return ", ".join(f"MAP {host} ~NOTFOUND, MAP *.{host} ~NOTFOUND" for host in ANALYTICS_HOSTS)


def is_e2e_item(item: pytest.Item) -> bool:
    """Limit czasu dotyczy tylko scenariuszy z katalogu e2e obok tego pliku."""
    return Path(item.path).is_relative_to(_E2E_DIR)


def format_limit(seconds: float) -> str:
    """Wypisuje limit bez zbędnych zer po przecinku."""
    return f"{seconds:g}"


def pytest_configure(config):
    """Odczytuje zmienne środowiskowe raz na sesję, żeby błędna wartość przerwała ją od razu."""
    config.stash[_MAX_SECONDS_KEY] = parse_positive_number(
        "E2E_MAX_SECONDS", os.environ.get("E2E_MAX_SECONDS"), DEFAULT_E2E_MAX_SECONDS
    )
    expect_timeout = parse_positive_number(
        "E2E_EXPECT_TIMEOUT_MS", os.environ.get("E2E_EXPECT_TIMEOUT_MS"), DEFAULT_E2E_EXPECT_TIMEOUT_MS
    )
    expect.set_options(timeout=expect_timeout)
    config.stash[_ALLOW_ANALYTICS_KEY] = parse_flag("E2E_ALLOW_ANALYTICS", os.environ.get("E2E_ALLOW_ANALYTICS"))


@pytest.hookimpl(wrapper=True, trylast=True)
def pytest_runtest_call(item):
    """Mierzy sam scenariusz (bez przygotowania przeglądarki) i przekroczenie limitu zgłasza jako zwykły błąd.

    Błąd powstaje w fazie "call", więc pytest-playwright traktuje test jak każdy
    inny nieudany: zapisuje zrzut ekranu i ślad, a JUnit i HTML dostają wynik.
    `trylast` umieszcza pomiar najbliżej funkcji testu, wewnątrz przechwytywania
    wyjścia, dzięki czemu wypisany czas trafia do system-out raportu JUnit.
    """
    if not is_e2e_item(item):
        return (yield)
    limit = item.config.stash[_MAX_SECONDS_KEY]
    start = time.monotonic()
    try:
        result = yield
    finally:
        elapsed = time.monotonic() - start
        print(f"DURATION: {elapsed:.1f} s (limit {format_limit(limit)} s)")
    # Wcześniejszy błąd testu pozostaje głównym; przekroczenie limitu zgłaszamy tylko po sukcesie.
    if elapsed > limit:
        raise AssertionError(
            f"E2E test took {elapsed:.1f} s, which exceeds the limit of {format_limit(limit)} s "
            "(E2E_MAX_SECONDS)"
        )
    return result


@pytest.fixture(scope="session")
def browser_type_launch_args(browser_type_launch_args, pytestconfig):
    """Blokuje analitykę dla całej przeglądarki, więc ruch testowy nie trafia do GA4.

    Reguły rozwiązywania nazw działają w Chromium dla sesji klienta i administratora.
    Nie używamy context.route, bo włączone przechwytywanie wyłącza w Playwright
    pamięć podręczną HTTP i każda nawigacja pobierałaby od nowa style i skrypty.
    E2E_ALLOW_ANALYTICS=1 wyłącza blokadę.
    """
    if pytestconfig.stash[_ALLOW_ANALYTICS_KEY]:
        return browser_type_launch_args
    args = [*browser_type_launch_args.get("args", []), f"--host-resolver-rules={analytics_resolver_rules()}"]
    return {**browser_type_launch_args, "args": args}


@pytest.fixture(scope="session")
def browser_context_args(browser_context_args, base_url):
    """Ustawia adres sklepu, obsługę lokalnego HTTPS i rozmiar okna.

    Limit oczekiwania asercji ustawia pytest_configure na podstawie E2E_EXPECT_TIMEOUT_MS.
    """
    return {
        **browser_context_args,
        "base_url": base_url or os.environ.get("BASE_URL", "https://localhost"),
        "ignore_https_errors": True,
        "locale": "pl-PL",
        "timezone_id": "Europe/Warsaw",
        "viewport": {"width": 1280, "height": 720},
    }


@pytest.fixture
def admin_page(browser, browser_context_args):
    """Osobna sesja administratora, która nie miesza ciasteczek panelu z sesją klienta.

    Kontekst powstaje poza nagrywaniem pytest-playwright, bo ślad przy błędzie
    zawierałby hasło administratora wpisane w formularz logowania.
    """
    context = browser.new_context(**browser_context_args)
    yield context.new_page()
    context.close()
