"""
Weryfikacja działania i uprawnień Webservice API PrestaShop.

Kryteria:
- Połączenie i uwierzytelnienie API (Basic Auth) działa poprawnie.
- Dostępne są zasoby katalogowe (odczyt GET i zapis POST).
- Zasoby chronione (customers, orders, configurations) są zablokowane (HTTP 401/403).
- Wszelkie anomalie uprawnień kończą test kodem wyjścia różnym od 0.
"""

from __future__ import annotations

import sys
import time
from prestashop_api import (
    PrestaShopClient,
    PrestaShopAuthError,
    PrestaShopConnectionError,
    PrestaShopAPIError,
)


def test_api() -> None:
    print("=== Test połączenia z PrestaShop Webservice API ===")
    errors: list[str] = []

    try:
        client = PrestaShopClient()
        print(f"Adres API: {client.api_url}")
        print("Łączenie z API...")

        client.check_connection()
        print(" [OK] Połączenie nawiązane, uwierzytelnienie powiodło się!\n")

        # 1. Sprawdzenie uprawnień do odczytu kluczowych zasobów katalogu
        print("Sprawdzanie uprawnień do odczytu zasobów katalogu (GET):")
        for resource in ["categories", "products", "combinations", "stock_availables"]:
            try:
                res = client.get(resource, params={"limit": 1})
                print(f" - GET  {resource:<18} [OK]")
            except PrestaShopAuthError:
                print(f" - GET  {resource:<18} [BŁĄD: BRAK UPRAWNIEŃ 401/403]")
                errors.append(f"Brak uprawnień do odczytu wymaganego zasobu katalogu: {resource}")
            except Exception as e:
                print(f" - GET  {resource:<18} [BŁĄD: {e}]")
                errors.append(f"Błąd podczas odczytu zasobu {resource}: {e}")

        # 2. Potwierdzenie zapisu do katalogu (POST / DELETE)
        print("\nSprawdzanie uprawnień do zapisu w katalogu (POST / DELETE):")
        test_cat_name = f"Test API Category {int(time.time())}"
        test_cat_data = {
            "name": {"1": test_cat_name},
            "link_rewrite": {"1": "test-api-cat"},
            "id_parent": 2,
            "active": 0,
        }
        created_cat_id = None
        try:
            created = client.post("categories", test_cat_data)
            if isinstance(created, dict) and "category" in created and "id" in created["category"]:
                created_cat_id = int(created["category"]["id"])
                print(f" - POST categories         [OK] Utworzono testowy rekord ID: {created_cat_id}")
            else:
                print(f" - POST categories         [BŁĄD: Niepoprawna odpowiedź: {created}]")
                errors.append("Zapis do zasobu 'categories' nie zwrócił oczekiwanego ID rekordu.")
        except Exception as e:
            print(f" - POST categories         [BŁĄD: {e}]")
            errors.append(f"Nie powiódł się zapis POST do katalogu (categories): {e}")

        # Sprzątnięcie rekordu testowego
        if created_cat_id is not None:
            try:
                client.delete("categories", created_cat_id)
                print(f" - DELETE categories/{created_cat_id:<5} [OK] Rekord testowy posprzątany")
            except Exception as e:
                print(f" - DELETE categories/{created_cat_id} [OSTRZEŻENIE: Nie udało się usunąć: {e}]")

        # 3. Sprawdzenie blokady odczytu zasobów wrażliwych (Least Privilege)
        print("\nSprawdzanie blokady zasobów nieuprawnionych (Least Privilege):")
        for forbidden in ["customers", "orders", "configurations"]:
            try:
                client.get(forbidden, params={"limit": 1})
                print(f" - GET  {forbidden:<18} [BŁĄD KRYTYCZNY - Zasób dostępny, powinien być zablokowany!]")
                errors.append(f"Zasób chroniony '{forbidden}' jest dostępny do odczytu (powinien być zablokowany)!")
            except PrestaShopAuthError:
                print(f" - GET  {forbidden:<18} [PRAWIDŁOWO ZABLOKOWANY (401/403)]")
            except Exception as e:
                print(f" - GET  {forbidden:<18} [BŁĄD: {e}]")
                errors.append(f"Nieoczekiwany błąd przy sprawdzaniu blokady '{forbidden}': {e}")

        # 4. Sprawdzenie blokady zapisu do zasobów wrażliwych (POST customers, orders)
        for forbidden_write in ["customers", "orders"]:
            try:
                client.post(forbidden_write, "<prestashop></prestashop>")
                print(f" - POST {forbidden_write:<18} [BŁĄD KRYTYCZNY - Zapis dozwolony, powinien być zablokowany!]")
                errors.append(f"Zapis do zasobu chronionego '{forbidden_write}' powiódł się (powinien być zablokowany)!")
            except PrestaShopAuthError:
                print(f" - POST {forbidden_write:<18} [PRAWIDŁOWO ZABLOKOWANY (401/403)]")
            except Exception as e:
                print(f" - POST {forbidden_write:<18} [BŁĄD: {e}]")
                errors.append(f"Nieoczekiwany błąd przy sprawdzaniu zapisu do '{forbidden_write}': {e}")

    except PrestaShopAuthError as e:
        print(f" [BŁĄD AUTORYZACJI] Błędny klucz API lub brak dostępu: {e}")
        errors.append(f"Błąd uwierzytelniania: {e}")
    except PrestaShopConnectionError as e:
        print(f" [BŁĄD POŁĄCZENIA] Nie można połączyć się ze sklepem: {e}")
        errors.append(f"Błąd połączenia: {e}")
    except Exception as e:
        print(f" [NIEOCZEKIWANY BŁĄD] {e}")
        errors.append(f"Nieoczekiwany błąd: {e}")

    # Raport końcowy i kod wyjścia
    if errors:
        print("\n=== TEST NIE POWIÓDŁ SIĘ (wykryto błędy) ===")
        for err in errors:
            print(f" [!] {err}")
        if "pytest" in sys.modules:
            assert not errors, f"Testy API zakończone błędami: {errors}"
        sys.exit(1)

    print("\n=== Wszystkie kryteria zakończenia spełnione! ===")
    if "pytest" not in sys.modules:
        sys.exit(0)


if __name__ == "__main__":
    test_api()
