"""Cechy produktu pochodzą wyłącznie ze struktury źródła, nie z domysłów z opisu."""

import json


def scrap_features(soup):
    details = soup.select_one("#product-details[data-product]")
    features = []
    if details:
        data = json.loads(details["data-product"])
        raw = data.get("features") or []
        if not isinstance(raw, list):
            raise ValueError("Niepoprawna lista cech źródła.")
        if any(
            not isinstance(item, dict)
            or not isinstance(item.get("name"), str)
            or not isinstance(item.get("value"), str)
            for item in raw
        ):
            raise ValueError("Niepoprawna cecha źródła.")
        features = [
            {"name": item["name"].strip(), "value": item["value"].strip()}
            for item in raw
        ]
    if not features:
        table = soup.select_one(".product-features .data-sheet")
        if table:
            names, values = table.select("dt.name"), table.select("dd.value")
            if len(names) != len(values):
                raise ValueError("Niekompletna tabela cech źródła.")
            features = [
                {
                    "name": n.get_text(" ", strip=True),
                    "value": v.get_text(" ", strip=True),
                }
                for n, v in zip(names, values)
            ]
    if any(not item["name"] or not item["value"] for item in features):
        raise ValueError("Pusta cecha źródła.")
    return list({(item["name"], item["value"]): item for item in features}.values())
