"""Kontrola Webservice tylko do odczytu; testy zapisu są na osobnej instancji D2."""

from __future__ import annotations

import xml.etree.ElementTree as ET

from prestashop_api import PrestaShopAPIError, PrestaShopAuthError, PrestaShopClient

READ_ONLY = {
    "languages",
    "shops",
    "currencies",
    "countries",
    "taxes",
    "tax_rules",
    "tax_rule_groups",
}
CATALOG = {
    "categories",
    "products",
    "combinations",
    "product_options",
    "product_option_values",
    "product_features",
    "product_feature_values",
    "images",
}


def check_permissions(client: PrestaShopClient) -> None:
    """Sprawdza reklamowaną macierz, odczyty i rzeczywistą odmowę zasobów chronionych."""
    client.check_connection()
    root = ET.fromstring(client._send("GET", client.api_url).content)
    advertised = {node.tag: node.attrib for node in root.find("api")}
    for resource in READ_ONLY | CATALOG | {"stock_availables"}:
        if resource not in advertised:
            raise PrestaShopAPIError(f"Brak zasobu {resource} w API.")
        expected = {"get"}
        if resource in CATALOG:
            expected |= {"post", "put"}
        if resource == "stock_availables":
            expected.add("put")
        actual = {
            method
            for method in ("get", "post", "put", "delete", "head", "patch")
            if advertised[resource].get(method) == "true"
        }
        if actual != expected:
            raise PrestaShopAPIError(
                f"Niepoprawna macierz uprawnień {resource}: {sorted(actual)}."
            )
        if resource != "images":
            client.get(resource, params={"limit": 1})
    for resource in ("customers", "orders", "configurations", "employees"):
        try:
            client.get(resource, params={"limit": 1})
        except PrestaShopAuthError:
            continue
        raise PrestaShopAPIError(f"Nieuprawniony odczyt zasobu {resource}.")


if __name__ == "__main__":
    try:
        check_permissions(PrestaShopClient())
    except (PrestaShopAPIError, ValueError) as error:
        print(f"ERROR: {error}")
        raise SystemExit(1) from None
    print("OK: połączenie, tożsamość i uprawnienia API; kontrola bez zapisów.")
