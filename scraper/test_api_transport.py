"""Regresje kontraktu HTTP, bez połączeń sieciowych."""

import json
import xml.etree.ElementTree as ET
from unittest.mock import Mock

import pytest
from prestashop_api import (
    PrestaShopAPIError,
    PrestaShopAuthError,
    PrestaShopClient,
    PrestaShopConnectionError,
    PrestaShopNotFoundError,
    PrestaShopProtocolError,
    PrestaShopValidationError,
    dict_to_prestashop_xml,
)
from requests import Response
from requests.exceptions import ConnectionError, Timeout


def response(status=200, body=None, raw=None, identity=None):
    result = Response()
    result.status_code = status
    result._content = (
        raw
        if raw is not None
        else json.dumps(body if body is not None else {}).encode()
    )
    if identity:
        result.headers["X-PrestaShop-Import-Instance"] = identity
    return result


@pytest.fixture
def client(monkeypatch):
    monkeypatch.setattr("prestashop_api._load_env_file", lambda: None)
    monkeypatch.setattr("prestashop_api.time.sleep", lambda _: None)
    result = PrestaShopClient("https://shop.example/api", "a" * 32)
    result.session.request = Mock()
    return result


@pytest.mark.parametrize(
    ("status", "error"),
    [
        (400, PrestaShopValidationError),
        (401, PrestaShopAuthError),
        (403, PrestaShopAuthError),
        (404, PrestaShopNotFoundError),
        (429, PrestaShopAPIError),
        (500, PrestaShopAPIError),
    ],
)
def test_http_statuses(client, status, error):
    client.session.request.return_value = response(
        status, raw=b"sensitive-server-response"
    )
    with pytest.raises(error) as raised:
        client.get("categories")
    assert raised.value.status_code == status
    assert "sensitive" not in str(raised.value)


@pytest.mark.parametrize(
    "raw",
    [
        b"<html>login</html>",
        b"<prestashop/>",
        b"{broken",
        b"[]",
        b"null",
        b"",
        b'{"errors":[]}',
    ],
)
def test_invalid_success_is_not_empty_catalog(client, raw):
    client.session.request.return_value = response(raw=raw)
    with pytest.raises(PrestaShopProtocolError):
        client.get("categories")


@pytest.mark.parametrize("failure", [Timeout(), ConnectionError()])
def test_get_retries_but_post_never_retries(client, failure):
    client.session.request.side_effect = [failure, response(body={"categories": []})]
    assert client.get("categories") == {"categories": []}
    assert client.session.request.call_count == 2
    client.session.request.reset_mock(side_effect=True)
    client.session.request.side_effect = failure
    with pytest.raises(PrestaShopConnectionError):
        client.post("categories", {"name": {"1": "Test"}})
    assert client.session.request.call_count == 1


def test_transient_status_retry_and_auth_timeout(client):
    client.session.request.side_effect = [
        response(503),
        response(body={"categories": []}),
    ]
    client.get("categories")
    args = client.session.request.call_args.kwargs
    assert args["timeout"] == 15
    assert args["allow_redirects"] is False
    assert client.session.auth.username == "a" * 32
    assert client.session.auth.password == ""


def test_redirect_is_not_followed(client):
    client.session.request.return_value = response(302)
    with pytest.raises(PrestaShopAPIError):
        client.get("categories")
    assert client.session.request.call_count == 1


def test_identity_and_reinstallation(client):
    identity = "a" * 64
    client.session.request.return_value = response(
        raw=b"<prestashop><api/></prestashop>", identity=identity
    )
    assert client.get_instance_identity() == identity
    client.session.request.return_value = response(
        body={"categories": []}, identity="b" * 64
    )
    with pytest.raises(PrestaShopProtocolError):
        client.get("categories")


def test_missing_identity_blocks_connection(client):
    client.session.request.return_value = response(
        raw=b"<prestashop><api/></prestashop>"
    )
    with pytest.raises(PrestaShopProtocolError):
        client.check_connection()


@pytest.mark.parametrize(
    "options",
    [
        {"api_url": "ftp://shop/api"},
        {"api_url": "https://key@shop/api"},
        {"api_url": "https://shop/api?ws_key=secret"},
        {"api_url": "https://shop:bad/api"},
        {"api_url": "https://shop"},
        {"api_key": "short"},
        {"api_key": "ą" * 32},
        {"timeout": 0},
        {"timeout": float("nan")},
        {"timeout": True},
        {"get_retries": 6},
        {"verify_ssl": "false"},
    ],
)
def test_configuration_validation(monkeypatch, options):
    monkeypatch.setattr("prestashop_api._load_env_file", lambda: None)
    values = {"api_url": "https://shop/api", "api_key": "a" * 32, **options}
    with pytest.raises(ValueError):
        PrestaShopClient(**values)


def test_xml_escaping_languages_and_associations():
    xml = dict_to_prestashop_xml(
        "products",
        {
            "name": {"2": "Zażółć & <test>"},
            "associations": {"categories": [{"id": 7}, {"id": 8}]},
        },
    )
    root = ET.fromstring(xml)
    assert root.find("product/name/language").attrib == {"id": "2"}
    assert root.findtext("product/name/language") == "Zażółć & <test>"
    assert [
        node.text
        for node in root.findall("product/associations/categories/category/id")
    ] == ["7", "8"]


def test_upload_accepts_documented_xml(client):
    client.session.request.return_value = response(
        raw=b"<prestashop><image><id>7</id></image></prestashop>"
    )
    assert client.upload_image("products", 1, image_bytes=b"jpeg") == {
        "image": {"id": "7"}
    }


@pytest.mark.parametrize("body", [{}, {"category": {}}, {"category": {"id": 12}}])
def test_put_requires_confirmation_of_correct_id(client, body):
    client.session.request.return_value = response(body=body)
    with pytest.raises(PrestaShopProtocolError):
        client.put("categories", 10, {"name": {"2": "Nazwa"}})


def test_put_wrapped_payload_and_mismatched_id(client):
    client.session.request.return_value = response(body={"category": {"id": 10}})
    client.put("categories", 10, {"category": {"name": {"2": "Nazwa"}}})
    payload = client.session.request.call_args.kwargs["data"]
    root = ET.fromstring(payload)
    assert root.findtext("category/id") == "10" and root.find("id") is None
    with pytest.raises(ValueError):
        client.put("categories", 10, {"id": 11})


def test_invalid_ssl_environment_and_empty_key_do_not_fallback(monkeypatch):
    monkeypatch.setattr("prestashop_api._load_env_file", lambda: None)
    monkeypatch.setenv("PRESTASHOP_API_VERIFY_SSL", "tru")
    with pytest.raises(ValueError):
        PrestaShopClient("https://shop/api", "a" * 32)
