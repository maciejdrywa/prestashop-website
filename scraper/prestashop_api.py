"""
Komponent odpowiedzialny za komunikację z PrestaShop Webservice API.
Sluzy do wymuszenia formatu JSON, obslugi bledow i Basic AUTH
"""

from __future__ import annotations

from pathlib import Path
import os
import urllib.parse
from typing import Any, Dict, Optional
import requests
from requests.auth import HTTPBasicAuth
from requests.exceptions import ConnectionError, Timeout, RequestException


class PrestaShopAPIError(Exception):
    #Bazowy wyjątek dla błędów API PrestaShop#

    def __init__(self, message: str, status_code: Optional[int] = None, response_text: str = ""):
        super().__init__(message)
        self.status_code = status_code
        self.response_text = response_text


class PrestaShopAuthError(PrestaShopAPIError):
    #Błąd uwierzytelniania lub brak uprawnień (HTTP 401 / 403).
    pass


class PrestaShopNotFoundError(PrestaShopAPIError):
    """Zasób nie został odnaleziony (HTTP 404)."""
    pass


class PrestaShopValidationError(PrestaShopAPIError):
    #Błąd walidacji danych przez PrestaShop (HTTP 400).
    pass


class PrestaShopConnectionError(PrestaShopAPIError):
    #Problem z połączeniem sieciowym lub certyfikatem SSL.
    pass

def _load_env_file() -> None:
    #Wczytuje zmienne z pliku .env do os.environ, jeśli nie zostały wcześniej ustawione.
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

    #Klient HTTP do komunikacji z PrestaShop Webservice REST API.

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
            # wyłączamy weryfikację
            env_verify = os.getenv("PRESTASHOP_API_VERIFY_SSL", "true").lower()
            self.verify_ssl = env_verify not in ("0", "false", "no")

        self.timeout = timeout

        if not self.api_key:
            raise ValueError("Klucz API PrestaShop nie został ustawiony (brak PRESTASHOP_API_KEY).")

        # Basic Auth: login = klucz API, hasło = puste
        self.auth = HTTPBasicAuth(self.api_key, "")

        self.session = requests.Session()
        self.session.auth = self.auth
        self.session.headers.update({
            "Accept": "application/json",
            "User-Agent": "PrestaShop-Import-Client/1.0",
        })

        if not self.verify_ssl:
            # Wyłączenie ostrzeżeń o braku weryfikacji self-signed certyfikatów
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

        # PrestaShop REST API domyślnie zwraca XML a my chcemy miec JSONa
        query_params = {"output_format": "JSON"}
        if params:
            query_params.update(params)

        try:
            response = self.session.request(
                method=method,
                url=url,
                params=query_params,
                json=json_data,
                data=xml_data.encode("utf-8") if xml_data else None,
                headers={"Content-Type": "application/xml"} if xml_data else None,
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

        # jesli pusta odpowiedz
        if not response.content.strip():
            return {}

        try:
            return response.json()
        except Exception:
            # W razie gdyby PrestaShop zwrócił XML mimo proszonego JSONa
            return {"raw_response": response.text}

    def get(self, resource: str, resource_id: Optional[int] = None, params: Optional[Dict[str, Any]] = None) -> Dict[
        str, Any]:
        #Pobiera listę zasobów lub pojedynczy rekord wg ID.
        endpoint = f"{resource}/{resource_id}" if resource_id is not None else resource
        return self._request("GET", endpoint, params=params)

    def post(self, resource: str, data: Dict[str, Any] | str) -> Dict[str, Any]:
        #Tworzy nowy zasób w API.
        if isinstance(data, str):
            return self._request("POST", resource, xml_data=data)
        return self._request("POST", resource, json_data=data)

    def put(self, resource: str, resource_id: int, data: Dict[str, Any] | str) -> Dict[str, Any]:
        #Aktualizuje istniejący zasób o danym ID.
        endpoint = f"{resource}/{resource_id}"
        if isinstance(data, str):
            return self._request("PUT", endpoint, xml_data=data)
        return self._request("PUT", endpoint, json_data=data)

    def check_connection(self) -> bool:
        """
        Sprawdza czy API jest dostępne i czy klucz jest poprawny.
        Odpytuje główny punkt wejścia /api bez wymuszania JSON (unika błędu PrestaShop 8.2).
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
