"""
Komponent odpowiedzialny za komunikację z PrestaShop Webservice API.
Służy do wymuszenia formatu JSON, obsługi błędów, Basic AUTH oraz konwersji XML dla operacji zapisu.
"""

from __future__ import annotations

import os
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Any, Dict, Optional, Union

import requests
from requests.auth import HTTPBasicAuth
from requests.exceptions import ConnectionError, RequestException, Timeout


class PrestaShopAPIError(Exception):
    """Bazowy wyjątek dla błędów API PrestaShop."""

    def __init__(self, message: str, status_code: Optional[int] = None, response_text: str = ""):
        super().__init__(message)
        self.status_code = status_code
        self.response_text = response_text


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


def _append_xml_subelements(parent: ET.Element, data: Any, key_name: Optional[str] = None) -> None:
    if isinstance(data, dict):
        for k, v in data.items():
            # Obsługa struktury wielojęzycznej: { "1": "Wartość PL", "2": "Value EN" }
            if isinstance(v, dict) and v and all(str(sub_k).isdigit() for sub_k in v.keys()):
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
                                _append_xml_subelements(item_el, item, singular_item_tag)
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


def dict_to_prestashop_xml(resource: str, data: Dict[str, Any], root_tag: Optional[str] = None) -> str:
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
        timeout: int = 15,
    ):
        _load_env_file()

        # Pobieranie konfiguracji ze zmiennych środowiskowych jeśli nie podano wprost
        self.api_url = (api_url or os.getenv("PRESTASHOP_API_URL", "https://localhost/api")).rstrip("/")
        self.api_key = api_key or os.getenv("PRESTASHOP_API_KEY", "")

        if verify_ssl is not None:
            self.verify_ssl = verify_ssl
        else:
            env_verify = os.getenv("PRESTASHOP_API_VERIFY_SSL", "true").lower()
            self.verify_ssl = env_verify not in ("0", "false", "no")

        self.timeout = timeout

        if not self.api_key:
            raise ValueError("Klucz API PrestaShop nie został ustawiony (brak PRESTASHOP_API_KEY w .env).")

        # Basic Auth: login = klucz API, hasło = puste
        self.auth = HTTPBasicAuth(self.api_key, "")

        self.session = requests.Session()
        self.session.auth = self.auth
        self.session.headers.update({
            "Accept": "application/json",
            "User-Agent": "PrestaShop-Import-Client/1.0",
        })

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

        try:
            response = self.session.request(
                method=method,
                url=url,
                params=query_params,
                json=json_data,
                data=xml_data.encode("utf-8") if xml_data else None,
                headers=headers if headers else None,
                verify=self.verify_ssl,
                timeout=self.timeout,
            )
        except (ConnectionError, Timeout) as exc:
            raise PrestaShopConnectionError(
                f"Nie udało się połączyć z API PrestaShop pod adresem {url}: {exc}"
            ) from exc
        except RequestException as exc:
            raise PrestaShopConnectionError(f"Błąd zapytania HTTP: {exc}") from exc

        # Obsługa kodów błędów HTTP
        if response.status_code in (401, 403):
            raise PrestaShopAuthError(
                f"Błąd autoryzacji API ({response.status_code}). Sprawdź poprawność klucza i jego uprawnienia.",
                status_code=response.status_code,
                response_text=response.text,
            )

        if response.status_code == 404:
            raise PrestaShopNotFoundError(
                f"Nie znaleziono zasobu pod adresem {url} (HTTP 404).",
                status_code=404,
                response_text=response.text,
            )

        if response.status_code == 400:
            raise PrestaShopValidationError(
                f"Błąd walidacji żądania przez PrestaShop (HTTP 400): {response.text}",
                status_code=400,
                response_text=response.text,
            )

        if not response.ok:
            raise PrestaShopAPIError(
                f"Błąd API PrestaShop ({response.status_code}): {response.text}",
                status_code=response.status_code,
                response_text=response.text,
            )

        if not response.content.strip():
            return {}

        try:
            return response.json()
        except Exception:
            return {"raw_response": response.text}

    def get(self, resource: str, resource_id: Optional[int] = None, params: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
        """Pobiera listę zasobów lub pojedynczy rekord wg ID."""
        endpoint = f"{resource}/{resource_id}" if resource_id is not None else resource
        return self._request("GET", endpoint, params=params)

    def post(self, resource: str, data: Dict[str, Any] | str, root_tag: Optional[str] = None) -> Dict[str, Any]:
        """
        Tworzy nowy zasób w API.
        Przyjmuje słownik Pythona (automatycznie konwertowany na XML) lub gotowy ciąg XML.
        """
        if isinstance(data, dict):
            xml_payload = dict_to_prestashop_xml(resource, data, root_tag=root_tag)
        elif isinstance(data, str):
            if "<prestashop" not in data:
                tag = root_tag or RESOURCE_SINGULAR_MAP.get(resource, resource.rstrip("s"))
                xml_payload = f'<?xml version="1.0" encoding="UTF-8"?><prestashop xmlns:xlink="http://www.w3.org/1999/xlink"><{tag}>{data}</{tag}></prestashop>'
            else:
                xml_payload = data
        else:
            raise TypeError("Dane do metody post() muszą być słownikiem lub ciągiem znaków XML.")

        return self._request("POST", resource, xml_data=xml_payload)

    def put(self, resource: str, resource_id: int, data: Dict[str, Any] | str, root_tag: Optional[str] = None) -> Dict[str, Any]:
        """
        Aktualizuje istniejący zasób o danym ID.
        Przyjmuje słownik Pythona (automatycznie uzupełniający pole id i konwertowany na XML) lub gotowy ciąg XML.
        """
        endpoint = f"{resource}/{resource_id}"
        if isinstance(data, dict):
            payload_dict = dict(data)
            if "id" not in payload_dict:
                payload_dict["id"] = resource_id
            xml_payload = dict_to_prestashop_xml(resource, payload_dict, root_tag=root_tag)
        elif isinstance(data, str):
            if "<prestashop" not in data:
                tag = root_tag or RESOURCE_SINGULAR_MAP.get(resource, resource.rstrip("s"))
                xml_payload = f'<?xml version="1.0" encoding="UTF-8"?><prestashop xmlns:xlink="http://www.w3.org/1999/xlink"><{tag}>{data}</{tag}></prestashop>'
            else:
                xml_payload = data
        else:
            raise TypeError("Dane do metody put() muszą być słownikiem lub ciągiem znaków XML.")

        return self._request("PUT", endpoint, xml_data=xml_payload)

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

        files = {"image": (fname, content, mime_type)}
        try:
            response = self.session.post(
                url,
                files=files,
                verify=self.verify_ssl,
                timeout=self.timeout,
            )
        except (ConnectionError, Timeout) as exc:
            raise PrestaShopConnectionError(
                f"Nie udało się połączyć z API PrestaShop przy wysyłaniu zdjęcia: {exc}"
            ) from exc
        except RequestException as exc:
            raise PrestaShopConnectionError(f"Błąd zapytania HTTP: {exc}") from exc

        if response.status_code in (401, 403):
            raise PrestaShopAuthError(
                f"Błąd autoryzacji podczas przesyłania zdjęcia ({response.status_code}).",
                status_code=response.status_code,
                response_text=response.text,
            )
        if not response.ok:
            raise PrestaShopAPIError(
                f"Błąd przesyłania zdjęcia ({response.status_code}): {response.text}",
                status_code=response.status_code,
                response_text=response.text,
            )
        try:
            return response.json()
        except Exception:
            return {"raw_response": response.text}

    def check_connection(self) -> bool:
        """
        Sprawdza czy API jest dostępne i czy klucz jest poprawny.
        Odpytuje punkt wejścia /api bez wymuszania JSON (unika błędu PrestaShop 8.2).
        """
        try:
            response = self.session.get(
                self.api_url,
                verify=self.verify_ssl,
                timeout=self.timeout,
            )
            if response.status_code in (401, 403):
                raise PrestaShopAuthError(
                    f"Błąd autoryzacji API ({response.status_code}). Błędny klucz API lub brak dostępu.",
                    status_code=response.status_code,
                    response_text=response.text,
                )
            if not response.ok:
                raise PrestaShopAPIError(
                    f"Błąd API PrestaShop ({response.status_code}): {response.text}",
                    status_code=response.status_code,
                    response_text=response.text,
                )
            return True
        except (ConnectionError, Timeout) as exc:
            raise PrestaShopConnectionError(
                f"Nie udało się połączyć z API PrestaShop pod adresem {self.api_url}: {exc}"
            ) from exc
        except RequestException as exc:
            raise PrestaShopConnectionError(f"Błąd zapytania HTTP: {exc}") from exc
