# PrestaShop Webservice API

Do automatycznego importu danych katalogowych wykorzystywane jest oficjalne Webservice API PrestaShop.

## 1. Zmienne środowiskowe

Konfiguracja połączenia z API przekazywana jest przez zmienne środowiskowe w pliku `.env`:

| Zmienna | Opis | Wartość domyślna / przykład |
|---|---|---|
| `PRESTASHOP_API_URL` | Adres punktu wejścia REST API | `https://localhost/api` |
| `PRESTASHOP_API_KEY` | 32-znakowy alfanumeryczny klucz API | *(definiowany w .env)* |
| `PRESTASHOP_API_VERIFY_SSL` | Weryfikacja certyfikatu TLS (self-signed w środowisku dev) | `false` |

Wzór konfiguracji znajduje się w [`.env.example`](../.env.example). Klucz w `.env.example` pozostaje pusty ze względów bezpieczeństwa.

## 2. Aktywacja API i uprawnienia klucza (*Least Privilege*)

Webservice API oraz klucz są konfigurowane automatycznie w trakcie inicjalizacji sklepu przez skrypt [`deploy/init/configure-webservice.php`](../deploy/init/configure-webservice.php).

Zgodnie z zasadą najmniejszych uprawnień (*Least Privilege*), klucz posiada wyłącznie uprawnienia do zasobów niezbędnych podczas importu katalogu:
- `categories`, `products`, `combinations`, `product_options`, `product_option_values`, `product_features`, `product_feature_values`, `images`, `stock_availables`, `manufacturers`, `taxes` (metody: `GET`, `POST`, `PUT`, `DELETE`, `HEAD`).

Wszystkie zasoby wrażliwe (np. `customers`, `orders`, `configurations`, `employees`) pozostają zablokowane (zwracają błąd HTTP 401/403).

## 3. Komponent klienta API

Za komunikację z Webservice API w języku Python odpowiada moduł [`scraper/prestashop_api.py`](../scraper/prestashop_api.py):
- Implementuje klasę `PrestaShopClient`, która automatycznie wczytuje konfigurację z `.env` lub zmiennych środowiskowych procesu.
- Obsługuje uwierzytelnianie HTTP Basic Auth (klucz jako login, puste hasło).
- Obsługuje format JSON dla odczytu (`output_format=JSON`) oraz automatyczną konwersję słowników do XML (wymaganego przez PrestaShop 8.2) dla operacji zapisu (`post`, `put`).
- Umożliwia przesyłanie zdjęć produktów i kategorii przez dedykowaną metodę `upload_image()` (`multipart/form-data`).
- Zawiera dedykowaną hierarchię wyjątków (`PrestaShopAuthError`, `PrestaShopNotFoundError`, `PrestaShopValidationError`, `PrestaShopConnectionError`, `PrestaShopAPIError`).

## 4. Test połączenia i weryfikacja uprawnień

Aby sprawdzić poprawność działania API, uwierzytelniania oraz blokadę nieuprawnionych zasobów:

```sh
python3 scraper/test_api_client.py
```
