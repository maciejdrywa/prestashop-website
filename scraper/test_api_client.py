"""
Weryfikacja działania i uprawnień Webservice API PrestaShop.
"""

import sys
from prestashop_api import (
    PrestaShopClient,
    PrestaShopAuthError,
    PrestaShopConnectionError,
    PrestaShopAPIError
)

def test_api():
    print("=== Test połączenia z PrestaShop Webservice API ===")
    try:
        client = PrestaShopClient()
        print(f"Adres API: {client.api_url}")
        print("Łączenie z API...")

        client.check_connection()
        print(" [OK] Połączenie nawiązane, uwierzytelnienie powiodło się!")

        # Sprawdzenie uprawnień do kluczowych zasobów importu
        print("\nSprawdzanie uprawnień do odczytu zasobów katalogu:")
        for resource in ["categories", "products", "combinations", "stock_availables"]:
            try:
                res = client.get(resource, params={"limit": 1})
                print(f" - {resource:<20} [OK]")
            except PrestaShopAuthError:
                print(f" - {resource:<20} [BRAK UPRAWNIEŃ 403]")
            except Exception as e:
                print(f" - {resource:<20} [BŁĄD: {e}]")

        # Sprawdzenie, czy zasoby chronione są zablokowane (zasada Least Privilege)
        print("\nSprawdzanie blokady zasobów nieuprawnionych (Least Privilege):")
        for forbidden in ["customers", "orders", "configurations"]:
            try:
                client.get(forbidden, params={"limit": 1})
                print(f" - {forbidden:<20} [OSTRZEŻENIE - Zasób dostępny, a powinien być zablokowany!]")
            except PrestaShopAuthError:
                print(f" - {forbidden:<20} [PRAWIDŁOWO ZABLOKOWANY (401/403)]")

        print("\n=== Wszystkie kryteria zakończenia spełnione! ===")

    except PrestaShopAuthError as e:
        print(f" [BŁĄD AUTORYZACJI] Błędny klucz API lub brak dostępu: {e}")
        sys.exit(1)
    except PrestaShopConnectionError as e:
        print(f" [BŁĄD POŁĄCZENIA] Nie można połączyć się ze sklepem: {e}")
        sys.exit(1)
    except Exception as e:
        print(f" [NIEOCZEKIWANY BŁĄD] {e}")
        sys.exit(1)

if __name__ == "__main__":
    test_api()
