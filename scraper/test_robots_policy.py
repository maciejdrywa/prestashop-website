"""Kontrola robots bez wysyłania żądań sieciowych."""

from contextlib import nullcontext
from types import SimpleNamespace
from unittest.mock import Mock

import pytest
import robots_policy
from playwright.sync_api import Error as PlaywrightError
from robots_policy import USER_AGENT, RobotsDenied, RobotsPolicy, source_get
from Scrapping_products.store_crawler import StoreCrawler

SOURCE = "https://pandomu.com.pl"


@pytest.mark.parametrize("path,allowed", [
    ("/5-product.html", True), ("/5-category?page=2", True),
    ("/1/2.jpg", True), ("/modules/demo/style.css", True),
    ("/modules/demo/script.js", True), ("/modules/demo/image.jpg", True),
    ("/js/jquery/jquery.js", True), ("/js/private.js", False),
    ("/config/settings.php", False), ("/modules/private.php", False),
    ("/?controller=cart", False), ("/?a=1&controller=cart", False),
    ("/?order=price", False), ("/?a=1&order=price", False),
    ("/?search_query=door", False),
])
def test_saved_rules(path, allowed):
    assert RobotsPolicy().allowed(SOURCE + path) is allowed


@pytest.mark.parametrize("url", [
    "https://example.org/test", "http://pandomu.com.pl/5.html",
    "https://pandomu.com.pl:444/5.html", "https://www.pandomu.com.pl/5.html",
    "https://user:secret@pandomu.com.pl/5.html",
])
def test_other_origins_are_denied(url):
    with pytest.raises(RobotsDenied):
        RobotsPolicy().require_allowed(url)


def test_specific_agent_and_end_anchor(tmp_path):
    path = tmp_path / "robots.txt"
    path.write_text("User-agent: *\nDisallow: /\nUser-agent: TwojeDrzwiScraper\n"
                    "Disallow: /private/*\nAllow: /private/public$\n", encoding="utf-8")
    policy = RobotsPolicy(path)
    assert policy.allowed(SOURCE + "/product")
    assert policy.allowed(SOURCE + "/private/public")
    assert not policy.allowed(SOURCE + "/private/public/extra")


@pytest.mark.parametrize("content", ["", "<html>Error</html>", "Disallow: /"])
def test_invalid_policy_fails_closed(tmp_path, content):
    path = tmp_path / "robots.txt"
    path.write_text(content, encoding="utf-8")
    with pytest.raises(ValueError):
        RobotsPolicy(path)


def test_missing_policy_fails_closed(tmp_path):
    with pytest.raises(OSError):
        RobotsPolicy(tmp_path / "missing.txt")


def test_page_denied_before_navigation(monkeypatch):
    crawler = StoreCrawler.__new__(StoreCrawler)
    crawler.robots = RobotsPolicy()
    crawler.page = Mock()
    sleep = Mock()
    monkeypatch.setattr("Scrapping_products.store_crawler.time.sleep", sleep)
    with pytest.raises(RobotsDenied):
        crawler.fetch_page(SOURCE + "/config/private")
    crawler.page.goto.assert_not_called()
    sleep.assert_not_called()


def http_response(status=200, location=None):
    return Mock(status_code=status, headers={"Location": location} if location else {})


@pytest.mark.parametrize("target", ["/config/private", "https://example.org/test"])
def test_http_redirect_denied_before_following(target):
    session = Mock()
    session.get.return_value = nullcontext(http_response(302, target))
    with pytest.raises(RobotsDenied):
        with source_get(session, SOURCE + "/product"):
            pytest.fail("Blocked redirect must not yield")
    assert session.get.call_count == 1
    assert session.get.call_args.kwargs["allow_redirects"] is False
    assert session.get.call_args.kwargs["headers"]["User-Agent"] == USER_AGENT


def test_http_allowed_redirect():
    session = Mock()
    final = http_response()
    session.get.side_effect = [nullcontext(http_response(302, "/product")), nullcontext(final)]
    with source_get(session, SOURCE + "/old") as response:
        assert response is final
    assert session.get.call_args.args[0] == SOURCE + "/product"


def test_http_redirect_limit():
    session = Mock()
    session.get.side_effect = lambda *a, **kw: nullcontext(http_response(302, "/loop"))
    with pytest.raises(ValueError, match="limit"):
        with source_get(session, SOURCE + "/loop"):
            pass
    assert session.get.call_count == robots_policy.MAX_REQUESTS


def browser_route(url="/product", method="GET"):
    return Mock(request=SimpleNamespace(url=SOURCE + url, method=method))


def test_browser_denied_without_fetch():
    route = browser_route("/config/private")
    RobotsPolicy().route(route)
    route.fetch.assert_not_called()
    route.abort.assert_called_once_with("blockedbyclient")


def test_browser_checks_redirect():
    route = browser_route()
    response = Mock(status=302, headers={"location": "/config/private"})
    route.fetch.return_value = response
    RobotsPolicy().route(route)
    assert route.fetch.call_count == 1
    route.fulfill.assert_not_called()
    response.dispose.assert_called_once()
    route.abort.assert_called_once_with("blockedbyclient")


def test_browser_allowed_redirect():
    route = browser_route()
    first = Mock(status=302, headers={"location": "/public"})
    final = Mock(status=200)
    route.fetch.side_effect = [first, final]
    RobotsPolicy().route(route)
    assert route.fetch.call_args.kwargs == {"url": SOURCE + "/public", "max_redirects": 0}
    route.fulfill.assert_called_once_with(response=final)
    route.abort.assert_not_called()
    first.dispose.assert_called_once()
    final.dispose.assert_called_once()


def test_browser_fetch_error_aborts():
    route = browser_route()
    route.fetch.side_effect = PlaywrightError("offline")
    RobotsPolicy().route(route)
    route.abort.assert_called_once_with("failed")


def test_variant_denied_before_post(monkeypatch):
    crawler = StoreCrawler.__new__(StoreCrawler)
    crawler.robots = RobotsPolicy()
    crawler.context = Mock()
    monkeypatch.setattr("Scrapping_products.store_crawler.collect_variants",
                        lambda html, product, refresh: refresh({}))
    with pytest.raises(RobotsDenied):
        crawler.fetch_variants('<form id="add-to-cart-or-refresh"></form>',
                               SimpleNamespace(product_url=SOURCE + "/?controller=cart"))
    crawler.context.request.post.assert_not_called()


def test_image_fallback_denied(tmp_path):
    from image_import.cache import ImageCache
    session = Mock()
    cache = ImageCache(tmp_path, session=session, use_release=False)
    with pytest.raises(RobotsDenied):
        cache.download(SOURCE + "/config/image.jpg", tmp_path / "image.jpg", 100)
    session.get.assert_not_called()
    assert not (tmp_path / "image.jpg").exists()


def test_release_download_does_not_use_source_rules(tmp_path, monkeypatch):
    from image_import.cache import RELEASE_URL, ImageCache
    monkeypatch.setattr(robots_policy, "source_policy", Mock(side_effect=AssertionError("source rules")))
    session = Mock()
    response = http_response()
    response.iter_content.return_value = [b"archive"]
    session.get.return_value = nullcontext(response)
    target = tmp_path / "archive.zip"
    ImageCache(tmp_path, session=session).download(RELEASE_URL, target, 100)
    assert target.read_bytes() == b"archive"


def test_metadata_refresh_denied():
    from refresh_image_covers import fetch_source_soup
    with pytest.raises(RobotsDenied):
        fetch_source_soup({"product_url": SOURCE + "/config/private", "product_id": "5"})


def test_browser_setup_registers_policy(monkeypatch):
    playwright = Mock()
    context = playwright.chromium.launch_persistent_context.return_value
    context.pages = []
    monkeypatch.setattr("Scrapping_products.store_crawler.sync_playwright",
                        lambda: SimpleNamespace(start=lambda: playwright))
    crawler = StoreCrawler(headless=True)
    options = playwright.chromium.launch_persistent_context.call_args.kwargs
    assert options["user_agent"] == USER_AGENT
    assert options["service_workers"] == "block"
    context.route.assert_called_once_with("**/*", crawler.robots.route)


def test_browser_post_redirect_aborts():
    route = browser_route(method="POST")
    route.fetch.return_value = Mock(status=302, headers={"location": "/public"})
    RobotsPolicy().route(route)
    assert route.fetch.call_count == 1
    route.fulfill.assert_not_called()
    route.abort.assert_called_once_with("blockedbyclient")
