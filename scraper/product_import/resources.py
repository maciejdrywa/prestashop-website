"""Odczyt schematów, zachowanie pól PUT i idempotentne zapisy z dziennikiem."""

from __future__ import annotations

import copy
import re
import xml.etree.ElementTree as ET
from collections import Counter
from decimal import Decimal, InvalidOperation

from bs4 import BeautifulSoup
from category_import.prestashop_sync import normalize_multilang_value, resource_records
from prestashop_api import (
    RESOURCE_SINGULAR_MAP,
    PrestaShopAPIError,
    PrestaShopProtocolError,
)


def html_content(value):
    return " ".join(
        BeautifulSoup(str(value), "html.parser").get_text(" ", strip=True).split()
    )


class Resources:
    def __init__(self, client, journal, context):
        self.client, self.journal, self.context = client, journal, context
        self.cache, self.schemas = {}, {}
        self.visited = set()
        self.counts = {}

    def many(self, resource, params=None):
        rows, offset = [], 0
        singular = RESOURCE_SINGULAR_MAP.get(resource, resource.rstrip("s"))
        while True:
            result = self.client.get(
                resource,
                params={
                    "display": "full",
                    "sort": "[id_ASC]",
                    "limit": f"{offset},200",
                    "id_shop": self.context["shop_id"],
                    **(params or {}),
                },
            )
            batch = resource_records(result, resource, singular)
            for row in batch:
                if not str(row.get("id", "")).isdigit() or int(row["id"]) <= 0:
                    raise PrestaShopProtocolError(f"Brak ID w liście {resource}.")
            rows.extend(batch)
            if len(batch) < 200:
                break
            offset += 200
        if len({int(row["id"]) for row in rows}) != len(rows):
            raise PrestaShopProtocolError(f"Powielone ID w liście {resource}.")
        return rows

    def all(self, resource):
        if resource not in self.cache:
            self.cache[resource] = self.many(resource)
        return self.cache[resource]

    def schema(self, resource):
        if resource not in self.schemas:
            response = self.client._send(
                "GET",
                self.client.api_url + "/" + resource,
                params={"schema": "synopsis", "id_shop": self.context["shop_id"]},
            )
            try:
                root = ET.fromstring(response.content)
            except ET.ParseError:
                raise PrestaShopProtocolError(
                    f"Niepoprawny schemat {resource}."
                ) from None
            singular = RESOURCE_SINGULAR_MAP.get(resource, resource.rstrip("s"))
            node = root.find(singular)
            if root.tag != "prestashop" or node is None or not list(node):
                raise PrestaShopProtocolError(
                    f"Brak definicji {resource} w schemacie API."
                )
            self.schemas[resource] = {
                field.tag: {
                    "writable": field.attrib.get(
                        "read_only", field.attrib.get("readOnly")
                    )
                    != "true",
                    "multilang": field.find("language") is not None,
                    "format": field.attrib.get("format", ""),
                }
                for field in node
                if field.tag != "associations"
            }
        self.schemas[resource]["id"] = {
            "writable": True,
            "multilang": False,
            "format": "isUnsignedId",
        }
        return self.schemas[resource]

    def lang(self, value):
        value = normalize_multilang_value(value)
        if isinstance(value, str):
            return {str(self.context["default_language_id"]): value}
        if not isinstance(value, dict) or any(not str(k).isdigit() for k in value):
            raise PrestaShopProtocolError("Niepoprawna wartość wielojęzyczna.")
        return {str(k): str(v) for k, v in value.items()}

    def label(self, value):
        return self.lang(value).get(str(self.context["default_language_id"]), "")

    def normalized(self, resource, key, value):
        schema = self.schema(resource).get(key, {})
        if schema.get("multilang"):
            result = self.lang(value)
            if key in {"description", "description_short"}:
                result = {
                    k: re.sub(
                        r">\s+<",
                        "><",
                        BeautifulSoup(v, "html.parser").decode(formatter="minimal"),
                    ).strip()
                    for k, v in result.items()
                }
            return result
        if isinstance(value, list):
            return sorted(
                (self.normalized(resource, key, item) for item in value), key=str
            )
        if isinstance(value, dict):
            return {k: self.normalized(resource, k, v) for k, v in value.items()}
        if key.startswith("id") or key in {
            "price",
            "weight",
            "quantity",
            "active",
            "state",
            "minimal_quantity",
            "depends_on_stock",
            "out_of_stock",
            "default_on",
        }:
            try:
                return Decimal(str(value))
            except InvalidOperation:
                return str(value or "")
        return str(value or "")

    def matches(self, resource, current, desired, allow_html_normalization=False):
        for key, value in desired.items():
            actual = current.get(key)
            if key == "associations":
                if not isinstance(actual, dict) or any(
                    self.normalized(resource, k, actual.get(k, []))
                    != self.normalized(resource, k, v)
                    for k, v in value.items()
                ):
                    return False
            elif self.schema(resource).get(key, {}).get("multilang"):
                left = self.normalized(resource, key, actual)
                right = self.normalized(resource, key, value)
                if any(left.get(lid) != content for lid, content in right.items()):
                    if allow_html_normalization and key in {
                        "description",
                        "description_short",
                    }:
                        original = self.lang(actual)
                        expected = self.lang(value)
                        if all(
                            html_content(original.get(lid, "")) == html_content(content)
                            for lid, content in expected.items()
                        ):
                            continue
                    return False
            elif self.normalized(resource, key, actual) != self.normalized(
                resource, key, value
            ):
                return False
        return True

    def find(self, resource, identity):
        # Przynależność (np. grupa cechy) jest częścią tożsamości wartości.
        rows = [
            row for row in self.all(resource) if self.matches(resource, row, identity)
        ]
        if len(rows) > 1:
            raise PrestaShopAPIError(
                f"Niejednoznaczna tożsamość zasobu {resource}; zapis zablokowany."
            )
        return rows[0] if rows else None

    def read(self, resource, rid):
        result = self.client.get(
            resource,
            rid,
            params={"id_shop": self.context["shop_id"], "display": "full"},
        )
        singular = RESOURCE_SINGULAR_MAP.get(resource, resource.rstrip("s"))
        row = result.get(singular)
        if row is None and resource in result:
            records = resource_records(result, resource, singular)
            row = records[0] if len(records) == 1 else None
        if not isinstance(row, dict) or str(row.get("id")) != str(rid):
            raise PrestaShopProtocolError(f"Niepotwierdzone ID {resource}.")
        return row

    def payload(self, resource, current, desired):
        schema = self.schema(resource)
        payload = {
            key: self.lang(value) if schema[key]["multilang"] else value
            for key, value in (current or {}).items()
            if key in schema and schema[key]["writable"] and value is not None
        }
        # Niezarządzane powiązania (np. obrazy i tagi) pozostają nietknięte.
        # Webservice aktualizuje tylko węzły associations obecne w żądaniu.
        payload.pop("associations", None)
        for key, value in copy.deepcopy(desired).items():
            if schema.get(key, {}).get("multilang") and isinstance(value, dict):
                payload[key] = {**payload.get(key, {}), **value}
            else:
                payload[key] = value
        return payload

    def sync(self, resource, key, identity, desired, new_defaults=None):
        try:
            return self._sync(resource, key, identity, desired, new_defaults)
        except (ValueError, PrestaShopAPIError):
            self.counts.setdefault(resource, Counter())["rejected"] += 1
            raise

    def _sync(self, resource, key, identity, desired, new_defaults=None):
        entry_key = resource + ":" + key
        previous = self.journal.data["records"].get(entry_key)
        current = self.find(resource, identity)
        if (
            previous
            and previous.get("id") is not None
            and (current is None or int(current["id"]) != previous["id"])
        ):
            raise PrestaShopAPIError(
                f"Zmieniła się zapisana tożsamość {resource}:{key}."
            )
        if previous and previous["state"] == "pending" and previous.get("id") is None:
            if current is None or int(current["id"]) in previous.get("before_ids", []):
                raise PrestaShopAPIError(
                    f"Niepewny wynik POST {resource}:{key}; wymagane uzgodnienie, bez kolejnego POST."
                )
        owned = previous.get("owned", False) if previous else current is None
        if (
            current
            and entry_key in self.visited
            and self.matches(resource, current, desired)
        ):
            return current, owned
        if current:
            current = self.read(resource, int(current["id"]))
            if resource == "combinations" and str(current.get("id_product")) != str(
                desired.get("id_product")
            ):
                raise PrestaShopAPIError("Kombinacja należy do innego produktu.")
            if (
                resource == "products"
                and int(current.get("id_shop_default", 0)) != self.context["shop_id"]
            ):
                raise PrestaShopAPIError("Produkt należy do innego sklepu.")
        record = {
            "state": "pending",
            "id": int(current["id"]) if current else None,
            "owned": owned,
            "path": [resource, key],
            "desired": desired,
        }
        if not current:
            # Tożsamość (referencja lub nazwa z rodzicem) nie istniała przed POST.
            # Nie zapisujemy wszystkich niezwiązanych ID przy każdej nowej kombinacji.
            record["before_ids"] = []
        counters = self.counts.setdefault(resource, Counter())
        comparison = desired
        if previous and previous["desired"] == desired:
            comparison = {**desired, **previous.get("confirmed_html", {})}
        if current and self.matches(resource, current, comparison):
            status = "skipped"
        else:
            self.journal.put(entry_key, record)
            payload = self.payload(resource, current, desired)
            if not current:
                payload = {**(new_defaults or {}), **payload}
                try:
                    result = self.client.post(resource, payload)
                except PrestaShopAPIError as exc:
                    # Jednoznaczne odrzucenie nie jest niepewnym wynikiem POST.
                    # Timeouty, błędy 5xx i niepoprawne potwierdzenia zachowują pending.
                    if exc.status_code in {400, 401, 403, 404, 405}:
                        self.journal.discard(entry_key)
                    raise
                singular = RESOURCE_SINGULAR_MAP[resource]
                created = result.get(singular)
                if not isinstance(created, dict) or not re.fullmatch(
                    r"[1-9][0-9]*", str(created.get("id", ""))
                ):
                    raise PrestaShopProtocolError(
                        f"POST nie potwierdził ID {resource}."
                    )
                rid = int(created["id"])
                if rid in {int(row["id"]) for row in self.all(resource)}:
                    raise PrestaShopProtocolError("POST zwrócił zastane ID.")
                status = "created"
            else:
                rid = int(current["id"])
                self.client.put(resource, rid, payload)
                status = "updated"
            current = self.read(resource, rid)
            if not self.matches(
                resource, current, desired, allow_html_normalization=True
            ):
                raise PrestaShopProtocolError(
                    f"Zapis {resource}:{key} nie odpowiada planowi."
                )
        record.pop("before_ids", None)
        record["confirmed_html"] = {
            key: {
                lid: self.lang(current.get(key, "")).get(lid, "")
                for lid in self.lang(desired[key])
            }
            for key in ("description", "description_short")
            if key in desired
        }
        record.update(state="done", id=int(current["id"]))
        if previous != record:
            self.journal.put(entry_key, record)
        rows = self.all(resource)
        self.cache[resource] = [
            row for row in rows if int(row["id"]) != int(current["id"])
        ] + [current]
        self.visited.add(entry_key)
        counters[status] += 1
        return current, owned
