"""Reguły lokalnej kopii robots.txt dla żądań do sklepu źródłowego."""

import logging
import re
from contextlib import contextmanager
from functools import lru_cache
from pathlib import Path
from urllib.parse import urljoin, urlsplit

from playwright.sync_api import Error as PlaywrightError
from protego import Protego

USER_AGENT = "TwojeDrzwiScraper/1.0 (+https://github.com/maciejdrywa/prestashop-website)"
ROBOTS_FILE = Path(__file__).resolve().parent / "html_files_and_robots" / "robots.txt"
REDIRECTS = {301, 302, 303, 307, 308}
MAX_REQUESTS = 6


class RobotsDenied(ValueError):
    """Żądanie zablokowane przed wysłaniem do źródła."""


class RobotsPolicy:
    def __init__(self, path=ROBOTS_FILE):
        content = Path(path).read_text(encoding="utf-8-sig")
        if not re.search(r"^\s*user-agent\s*:\s*[^\s#]+", content, re.I | re.M):
            raise ValueError("Brak poprawnych reguł User-agent w robots.txt.")
        self.rules = Protego.parse(content)

    def allowed(self, url):
        try:
            parsed = urlsplit(url)
            in_scope = (
                parsed.scheme == "https"
                and parsed.hostname == "pandomu.com.pl"
                and parsed.port in {None, 443}
                and parsed.username is None
                and parsed.password is None
            )
            return in_scope and self.rules.can_fetch(url, USER_AGENT)
        except ValueError:
            return False

    def require_allowed(self, url):
        if not self.allowed(url):
            raise RobotsDenied(f"robots.txt: zablokowany URL źródła: {url}")

    def route(self, route):
        """Sprawdza każdy krok przekierowania; route.continue_ omijałby tę kontrolę."""
        url = route.request.url
        for _ in range(MAX_REQUESTS):
            if not self.allowed(url):
                logging.warning("robots.txt: zablokowany zasób: %s", url)
                route.abort("blockedbyclient")
                return
            try:
                response = route.fetch(url=url, max_redirects=0)
            except PlaywrightError:
                route.abort("failed")
                return
            try:
                if response.status not in REDIRECTS:
                    route.fulfill(response=response)
                    return
                location = response.headers.get("location")
                if not location or route.request.method not in {"GET", "HEAD"}:
                    break
                url = urljoin(url, location)
            finally:
                response.dispose()
        route.abort("blockedbyclient")


@lru_cache(maxsize=1)
def source_policy():
    return RobotsPolicy()


@contextmanager
def source_get(session, url, **kwargs):
    """GET z kontrolą reguł przed każdym żądaniem, także po przekierowaniu."""
    policy = source_policy()
    headers = dict(kwargs.pop("headers", {}))
    headers = {key: value for key, value in headers.items() if key.lower() != "user-agent"}
    headers["User-Agent"] = USER_AGENT
    kwargs.pop("allow_redirects", None)
    for _ in range(MAX_REQUESTS):
        policy.require_allowed(url)
        with session.get(url, headers=headers, allow_redirects=False, **kwargs) as response:
            if response.status_code not in REDIRECTS:
                yield response
                return
            location = response.headers.get("Location")
            if not location:
                raise ValueError("Brak Location w przekierowaniu źródła.")
            url = urljoin(url, location)
    raise ValueError("Przekroczony limit przekierowań źródła.")
