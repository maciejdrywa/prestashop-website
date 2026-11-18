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
