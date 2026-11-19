"""Konfiguracja sesji E2E, niezależna od testów scrapera offline."""

import os

import pytest
from playwright.sync_api import expect


@pytest.fixture(scope="session")
def browser_context_args(browser_context_args, base_url):
    """Ustawia adres sklepu, obsługę lokalnego HTTPS, rozmiar okna i limit oczekiwania asercji."""
    expect.set_options(timeout=5000)
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
