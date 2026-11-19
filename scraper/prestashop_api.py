"""
Komponent odpowiedzialny za komunikację z PrestaShop Webservice API.
Służy do wymuszenia formatu JSON, obsługi błędów, Basic AUTH oraz konwersji XML dla operacji zapisu.
"""

from __future__ import annotations

import math
import os
import re
import time
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Any, Dict, Optional, Union
from urllib.parse import urlsplit

import requests
from requests.auth import HTTPBasicAuth
from requests.exceptions import ConnectionError, RequestException, Timeout


class PrestaShopAPIError(Exception):
    """Bazowy wyjątek dla błędów API PrestaShop."""

    def __init__(
        self, message: str, status_code: Optional[int] = None, response_text: str = ""
    ):
        super().__init__(message)
        self.status_code = status_code
        self.response_text = response_text


class PrestaShopProtocolError(PrestaShopAPIError):
    """Odpowiedź nie spełnia kontraktu API; nie wolno uznać jej za pusty katalog."""


class PrestaShopAuthError(PrestaShopAPIError):
    """Błąd uwierzytelniania lub brak uprawnień (HTTP 401 / 403)."""

    pass


class PrestaShopNotFoundError(PrestaShopAPIError):
    """Zasób nie został odnaleziony (HTTP 404)."""

    pass


class PrestaShopValidationError(PrestaShopAPIError):
    """Błąd walidacji danych przez PrestaShop (HTTP 400)."""

    pass


class PrestaShopConnectionError(PrestaShopAPIError):
    """Problem z połączeniem sieciowym lub certyfikatem SSL."""

    pass


RESOURCE_SINGULAR_MAP: Dict[str, str] = {
    "categories": "category",
    "products": "product",
    "combinations": "combination",
    "product_options": "product_option",
    "product_option_values": "product_option_value",
    "product_features": "product_feature",
    "product_feature_values": "product_feature_value",
    "images": "image",
    "stock_availables": "stock_available",
    "manufacturers": "manufacturer",
    "taxes": "tax",
    "customers": "customer",
    "orders": "order",
    "addresses": "address",
    "currencies": "currency",
    "languages": "language",
    "tags": "tag",
    "accessories": "product",
    "product_bundle": "product",
}

MULTILANG_FIELDS = {
    "name",
    "description",
    "description_short",
    "additional_description",
    "link_rewrite",
    "meta_title",
    "meta_description",
    "meta_keywords",
    "available_now",
    "available_later",
    "delivery_in_stock",
    "delivery_out_stock",
    "value",
    "public_name",
}


def _append_xml_subelements(
    parent: ET.Element, data: Any, key_name: Optional[str] = None
) -> None:
    if isinstance(data, dict):
        for k, v in data.items():
            # Obsługa struktury wielojęzycznej: { "1": "Wartość PL", "2": "Value EN" }
            if (
                isinstance(v, dict)
                and v
                and all(str(sub_k).isdigit() for sub_k in v.keys())
            ):
                sub_el = ET.SubElement(parent, k)
                for lang_id, lang_text in v.items():
                    lang_el = ET.SubElement(sub_el, "language", {"id": str(lang_id)})
                    lang_el.text = "" if lang_text is None else str(lang_text)
            elif k in MULTILANG_FIELDS and isinstance(v, str):
                # Automatyczne opakowanie pola wielojęzycznego w language id="1"
                sub_el = ET.SubElement(parent, k)
                lang_el = ET.SubElement(sub_el, "language", {"id": "1"})
                lang_el.text = v
            elif isinstance(v, dict):
                sub_el = ET.SubElement(parent, k)
                _append_xml_subelements(sub_el, v, k)
            elif isinstance(v, list):
                sub_el = ET.SubElement(parent, k)
                singular_item_tag = RESOURCE_SINGULAR_MAP.get(k)
                for item in v:
                    if singular_item_tag:
                        if isinstance(item, dict):
                            if len(item) == 1 and singular_item_tag in item:
                                _append_xml_subelements(sub_el, item, k)
                            else:
                                item_el = ET.SubElement(sub_el, singular_item_tag)
                                _append_xml_subelements(
                                    item_el, item, singular_item_tag
                                )
                        elif isinstance(item, (int, str)):
                            item_el = ET.SubElement(sub_el, singular_item_tag)
                            id_el = ET.SubElement(item_el, "id")
                            id_el.text = str(item)
                        else:
                            _append_xml_subelements(sub_el, item, k)
                    else:
                        _append_xml_subelements(sub_el, item, k)
            else:
                el = ET.SubElement(parent, k)
                el.text = "" if v is None else str(v)
    elif isinstance(data, list):
        for item in data:
            _append_xml_subelements(parent, item, key_name)
    else:
        parent.text = "" if data is None else str(data)


def dict_to_prestashop_xml(
    resource: str, data: Dict[str, Any], root_tag: Optional[str] = None
) -> str:
    """
    Konwertuje słownik Pythona na format XML wymagany przez PrestaShop Webservice.
    """
    singular = root_tag or RESOURCE_SINGULAR_MAP.get(resource)
    if not singular:
        if resource.endswith("ies"):
            singular = resource[:-3] + "y"
        elif resource.endswith("s"):
            singular = resource[:-1]
        else:
            singular = resource

    root = ET.Element("prestashop", {"xmlns:xlink": "http://www.w3.org/1999/xlink"})

    if "prestashop" in data and len(data) == 1:
        content = data["prestashop"]
        if isinstance(content, dict):
            for tag, val in content.items():
                child = ET.SubElement(root, tag)
                _append_xml_subelements(child, val, tag)
        else:
            child = ET.SubElement(root, singular)
            _append_xml_subelements(child, content, singular)
    elif singular in data and len(data) == 1:
        child = ET.SubElement(root, singular)
        _append_xml_subelements(child, data[singular], singular)
    else:
        child = ET.SubElement(root, singular)
        _append_xml_subelements(child, data, singular)

    return ET.tostring(root, encoding="utf-8", xml_declaration=True).decode("utf-8")


def _load_env_file() -> None:
    """Wczytuje zmienne z pliku .env do os.environ, jeśli nie zostały wcześniej ustawione."""
    candidates = [
        Path.cwd() / ".env",
        Path(__file__).resolve().parent.parent / ".env",
    ]
    for path in candidates:
        if path.is_file():
            try:
                with open(path, "r", encoding="utf-8") as f:
                    for line in f:
                        line = line.strip()
                        if not line or line.startswith("#") or "=" not in line:
                            continue
                        key, val = line.split("=", 1)
                        key = key.strip()
                        val = val.strip().strip("'\"")
                        if key and key not in os.environ:
                            os.environ[key] = val
                break
            except Exception:
                pass


class PrestaShopClient:
    """Klient HTTP do komunikacji z PrestaShop Webservice REST API."""

    def __init__(
        self,
        api_url: Optional[str] = None,
        api_key: Optional[str] = None,
        verify_ssl: Optional[bool] = None,
        timeout: float = 15,
        get_retries: int = 2,
    ):
        _load_env_file()

        # Pobieranie konfiguracji ze zmiennych środowiskowych jeśli nie podano wprost
        self.api_url = (
            api_url
            if api_url is not None
            else os.getenv("PRESTASHOP_API_URL", "https://localhost/api")
        ).rstrip("/")
        parsed = urlsplit(self.api_url)
        try:
            port = parsed.port
        except ValueError as exc:
            raise ValueError("Niepoprawny port PRESTASHOP_API_URL.") from exc
        if (
            parsed.scheme not in {"http", "https"}
            or not parsed.hostname
            or parsed.username
            or parsed.password
            or parsed.query
            or parsed.fragment
            or not parsed.path.endswith("/api")
            or any(ch.isspace() for ch in self.api_url)
        ):
            raise ValueError(
                "PRESTASHOP_API_URL musi być adresem HTTP(S) zakończonym /api, bez danych logowania i parametrów."
            )
        if port == 0:
            raise ValueError("Niepoprawny port PRESTASHOP_API_URL.")
        self.api_key = (
            api_key if api_key is not None else os.getenv("PRESTASHOP_API_KEY", "")
        )

        if verify_ssl is not None:
            if not isinstance(verify_ssl, bool):
                raise ValueError("verify_ssl musi być wartością bool.")
            self.verify_ssl = verify_ssl
        else:
            env_verify = os.getenv("PRESTASHOP_API_VERIFY_SSL", "true").lower()
            if env_verify not in {"0", "1", "true", "false", "yes", "no"}:
                raise ValueError(
                    "PRESTASHOP_API_VERIFY_SSL musi mieć wartość true/false."
                )
            self.verify_ssl = env_verify in {"1", "true", "yes"}

        if (
            isinstance(timeout, bool)
            or not isinstance(timeout, (int, float))
            or not math.isfinite(timeout)
            or timeout <= 0
        ):
            raise ValueError("Timeout musi być dodatnią, skończoną liczbą.")
        if (
            isinstance(get_retries, bool)
            or not isinstance(get_retries, int)
            or not 0 <= get_retries <= 5
        ):
            raise ValueError("get_retries musi być liczbą całkowitą 0–5.")
        self.timeout = timeout
        self.get_retries = get_retries
        self.instance_identity = None

        if not re.fullmatch(r"[A-Za-z0-9]{32}", self.api_key):
            raise ValueError("PRESTASHOP_API_KEY musi mieć 32 znaki alfanumeryczne.")

        # Basic Auth: login = klucz API, hasło = puste
        self.auth = HTTPBasicAuth(self.api_key, "")

        self.session = requests.Session()
        self.session.auth = self.auth
        self.session.headers.update(
            {
                "Accept": "application/json",
                "User-Agent": "PrestaShop-Import-Client/1.0",
            }
        )

        if not self.verify_ssl:
            import urllib3

            urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

    def _build_url(self, endpoint: str) -> str:
        endpoint = endpoint.lstrip("/")
        return f"{self.api_url}/{endpoint}"

    def _request(
        self,
        method: str,
        endpoint: str,
        params: Optional[Dict[str, Any]] = None,
        json_data: Optional[Dict[str, Any]] = None,
        xml_data: Optional[str] = None,
    ) -> Dict[str, Any]:
        url = self._build_url(endpoint)

        # PrestaShop REST API domyślnie zwraca XML, wymuszamy JSON
        query_params = {"output_format": "JSON"}
        if params:
            query_params.update(params)

        headers: Dict[str, str] = {}
        if xml_data:
            headers["Content-Type"] = "application/xml; charset=utf-8"

        response = self._send(
            method,
            url,
            params=query_params,
            json=json_data,
            data=xml_data.encode("utf-8") if xml_data else None,
            headers=headers or None,
        )
        if not response.content.strip():
            if method == "DELETE" or response.status_code == 204:
                return {}
            raise PrestaShopProtocolError("API zwróciło pustą odpowiedź.")
        try:
            result = response.json()
        except ValueError as exc:
            raise PrestaShopProtocolError("API nie zwróciło poprawnego JSON.") from exc
        if not isinstance(result, dict) or "errors" in result:
            raise PrestaShopProtocolError("Niepoprawna struktura odpowiedzi JSON API.")
        return result

    def _send(self, method: str, url: str, **kwargs):
        """Ponawia wyłącznie GET. Nie loguje treści odpowiedzi ani danych uwierzytelnienia."""
        retries = self.get_retries if method == "GET" else 0
        for attempt in range(retries + 1):
            try:
                response = self.session.request(
                    method,
                    url,
                    verify=self.verify_ssl,
                    timeout=self.timeout,
                    allow_redirects=False,
                    **kwargs,
                )
            except (ConnectionError, Timeout):
                if attempt < retries:
                    time.sleep(2**attempt)
                    continue
                raise PrestaShopConnectionError(
                    "Nie udało się połączyć z API PrestaShop."
                ) from None
            except RequestException:
                raise PrestaShopConnectionError("Błąd transportu HTTP API.") from None
            if response.status_code in {429, 500, 502, 503, 504} and attempt < retries:
                time.sleep(2**attempt)
                continue
            self._check_response(response)
            return response
        raise AssertionError("Unreachable")

    def _check_response(self, response):
        status = response.status_code
        error_type = {
            400: PrestaShopValidationError,
            401: PrestaShopAuthError,
            403: PrestaShopAuthError,
            404: PrestaShopNotFoundError,
        }.get(status, PrestaShopAPIError)
        if not 200 <= status < 300:
            raise error_type(
                f"API PrestaShop zwróciło HTTP {status}.", status_code=status
            )
        if (
            self.instance_identity is not None
            and response.headers.get("X-PrestaShop-Import-Instance")
            != self.instance_identity
        ):
            raise PrestaShopProtocolError(
                "Zmieniła się tożsamość instalacji API lub brakuje nagłówka instancji."
            )

    def get_instance_identity(self) -> str:
        """Odczytuje publiczny odcisk instalacji ustawiany przez konfigurator Webservice."""
        response = self._send("GET", self.api_url)
        try:
            root = ET.fromstring(response.content)
        except ET.ParseError as exc:
            raise PrestaShopProtocolError(
                "Punkt wejścia API nie zwrócił XML PrestaShop."
            ) from exc
        identity = response.headers.get("X-PrestaShop-Import-Instance", "")
        if (
            root.tag != "prestashop"
            or root.find("api") is None
            or not re.fullmatch(r"[a-f0-9]{64}", identity)
        ):
            raise PrestaShopProtocolError(
                "Brak tożsamości instalacji. Uruchom configure-webservice.php."
            )
        self.instance_identity = identity
        return identity

    def get(
        self,
        resource: str,
        resource_id: Optional[int] = None,
        params: Optional[Dict[str, Any]] = None,
    ) -> Dict[str, Any]:
        """Pobiera listę zasobów lub pojedynczy rekord wg ID."""
        endpoint = f"{resource}/{resource_id}" if resource_id is not None else resource
        return self._request("GET", endpoint, params=params)

    def post(
        self, resource: str, data: Dict[str, Any] | str, root_tag: Optional[str] = None
    ) -> Dict[str, Any]:
        """
        Tworzy nowy zasób w API.
        Przyjmuje słownik Pythona (automatycznie konwertowany na XML) lub gotowy ciąg XML.
        """
        if isinstance(data, dict):
            xml_payload = dict_to_prestashop_xml(resource, data, root_tag=root_tag)
        elif isinstance(data, str):
            if "<prestashop" not in data:
                tag = root_tag or RESOURCE_SINGULAR_MAP.get(
                    resource, resource.rstrip("s")
                )
                xml_payload = f'<?xml version="1.0" encoding="UTF-8"?><prestashop xmlns:xlink="http://www.w3.org/1999/xlink"><{tag}>{data}</{tag}></prestashop>'
            else:
                xml_payload = data
        else:
            raise TypeError(
                "Dane do metody post() muszą być słownikiem lub ciągiem znaków XML."
            )

        return self._request("POST", resource, xml_data=xml_payload)

    def put(
        self,
        resource: str,
        resource_id: int,
        data: Dict[str, Any] | str,
        root_tag: Optional[str] = None,
    ) -> Dict[str, Any]:
        """
        Aktualizuje istniejący zasób o danym ID.
        Przyjmuje słownik Pythona (automatycznie uzupełniający pole id i konwertowany na XML) lub gotowy ciąg XML.
        """
        endpoint = f"{resource}/{resource_id}"
        if isinstance(data, dict):
            singular = root_tag or RESOURCE_SINGULAR_MAP.get(
                resource, resource.rstrip("s")
            )
            wrapped = singular in data and len(data) == 1
            payload_dict = dict(data[singular] if wrapped else data)
            if "id" in payload_dict and str(payload_dict["id"]) != str(resource_id):
                raise ValueError("ID w danych PUT nie odpowiada ID zasobu.")
            payload_dict["id"] = resource_id
            xml_payload = dict_to_prestashop_xml(
                resource, payload_dict, root_tag=root_tag
            )
        elif isinstance(data, str):
            if "<prestashop" not in data:
                tag = root_tag or RESOURCE_SINGULAR_MAP.get(
                    resource, resource.rstrip("s")
                )
                xml_payload = f'<?xml version="1.0" encoding="UTF-8"?><prestashop xmlns:xlink="http://www.w3.org/1999/xlink"><{tag}>{data}</{tag}></prestashop>'
            else:
                xml_payload = data
        else:
            raise TypeError(
                "Dane do metody put() muszą być słownikiem lub ciągiem znaków XML."
            )

        result = self._request("PUT", endpoint, xml_data=xml_payload)
        singular = root_tag or RESOURCE_SINGULAR_MAP.get(resource, resource.rstrip("s"))
        record = result.get(singular)
        if not isinstance(record, dict) or str(record.get("id")) != str(resource_id):
            raise PrestaShopProtocolError(
                "PUT nie potwierdził ID zapisanego zasobu; wymagane uzgodnienie wyniku."
            )
        return result

    def delete(self, resource: str, resource_id: int) -> bool:
        """Usuwa zasób o danym ID."""
        endpoint = f"{resource}/{resource_id}"
        self._request("DELETE", endpoint)
        return True

    def upload_image(
        self,
        resource: str,
        resource_id: int,
        image_path: Optional[Union[Path, str]] = None,
        image_bytes: Optional[bytes] = None,
        mime_type: str = "image/jpeg",
        filename: str = "image.jpg",
        image_id: Optional[int] = None,
    ) -> Dict[str, Any]:
        """
        Przesyła plik graficzny (multipart/form-data) do wskazanego zasobu (np. 'products', 'categories').
        """
        url = self._build_url(f"images/{resource}/{resource_id}")
        if image_path:
            p = Path(image_path)
            content = p.read_bytes()
            fname = p.name
        elif image_bytes is not None:
            content = image_bytes
            fname = filename
        else:
            raise ValueError("Należy podać image_path lub image_bytes.")

        if image_id is not None and (
            isinstance(image_id, bool) or not isinstance(image_id, int) or image_id <= 0
        ):
            raise ValueError("Niepoprawne ID obrazu.")
        if image_id is not None:
            url += f"/{image_id}"
        params = {"output_format": "JSON"}
        if image_id is not None:
            # PHP 8.1 wypełnia $_FILES dla POST; dispatcher PS obsługuje ps_method=PUT.
            params["ps_method"] = "PUT"
        files = {"image": (fname, content, mime_type)}
        response = self._send("POST", url, files=files, params=params)
        if response.content.startswith(b"\xff\xd8"):
            # PS 8.2.8 zwraca JPEG także po POST. Nowe ID uzgadnia importer
            # z listą galerii zapisaną przed operacją; nie zgadujemy ID w kliencie.
            return {
                "image": {"id": str(image_id)} if image_id else {},
                "binary_response": True,
            }
        try:
            result = response.json()
            image = result.get("image") if isinstance(result, dict) else None
            if (
                isinstance(image, dict)
                and str(image.get("id", "")).isdigit()
                and int(image["id"]) > 0
                and "errors" not in result
            ):
                return result
        except ValueError:
            pass
        # Endpoint zdjęć w PS 8 może zwracać XML niezależnie od output_format.
        try:
            root = ET.fromstring(response.content)
            image = root.find("image")
            if (
                root.tag == "prestashop"
                and image is not None
                and str(image.findtext("id", "")).isdigit()
                and int(image.findtext("id")) > 0
            ):
                return {"image": {"id": image.findtext("id")}}
        except ET.ParseError:
            pass
        raise PrestaShopProtocolError("Niepoprawna odpowiedź po przesłaniu zdjęcia.")

    def image_ids(self, product_id: int) -> list[int]:
        """Lista XML; 404 oznacza pustą galerię uprzednio potwierdzonego produktu."""
        try:
            response = self._send(
                "GET", self._build_url(f"images/products/{product_id}")
            )
        except PrestaShopNotFoundError:
            return []
        try:
            root = ET.fromstring(response.content)
            node = root.find("image")
            if (
                root.tag != "prestashop"
                or node is None
                or node.attrib.get("id") != str(product_id)
            ):
                raise ValueError("Niepoprawna lista galerii.")
            ids = [int(item.attrib["id"]) for item in node.findall("declination")]
            if not ids or any(rid <= 0 for rid in ids) or len(ids) != len(set(ids)):
                raise ValueError("Niepoprawne ID galerii.")
            return ids
        except (ET.ParseError, ValueError, KeyError):
            raise PrestaShopProtocolError(
                "Niepoprawna odpowiedź listy obrazów API."
            ) from None

    def image_bytes(self, product_id: int, image_id: int) -> bytes:
        """Odczytuje oryginalny JPEG; bez interpretacji odpowiedzi binarnej jako JSON."""
        response = self._send(
            "GET", self._build_url(f"images/products/{product_id}/{image_id}")
        )
        if (
            not response.content.startswith(b"\xff\xd8")
            or len(response.content) > 20_000_000
        ):
            raise PrestaShopProtocolError("API nie zwróciło oryginalnego JPEG.")
        return response.content

    def check_connection(self) -> bool:
        """
        Sprawdza czy API jest dostępne i czy klucz jest poprawny.
        Odpytuje punkt wejścia /api bez wymuszania JSON (unika błędu PrestaShop 8.2).
        """
        self.get_instance_identity()
        return True
