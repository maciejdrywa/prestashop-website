"""Shared catalog persistence and category assignment for resumed scraping."""

import json
from pathlib import Path
import tempfile


def read_products(path: Path) -> list[dict]:
    if not path.exists():
        return []
    with path.open(encoding="utf-8") as stream:
        products = json.load(stream)
    if not isinstance(products, list):
        raise ValueError(f"Oczekiwano listy produktów: {path}")
    ids = [str(product["product_id"]) for product in products]
    if len(ids) != len(set(ids)):
        raise ValueError("Istniejący plik zawiera powtórzone ID produktów.")
    return products


def save_json(path: Path, data) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", dir=path.parent, delete=False) as stream:
        temporary = Path(stream.name)
        try:
            json.dump(data, stream, ensure_ascii=False, indent=2)
            stream.write("\n")
        except BaseException:
            stream.close()
            temporary.unlink(missing_ok=True)
            raise
    try:
        temporary.replace(path)
    finally:
        temporary.unlink(missing_ok=True)


def assign_categories(product: dict, discovered: dict) -> dict:
    """Keep raw source breadcrumbs and expose a compatible complete primary path."""
    paths = discovered["category_paths"]
    if not paths:
        raise ValueError(f"Produkt {product['product_id']} nie ma przypisania kategorii.")
    original = product.get("source_breadcrumbs", product.get("breadcrumbs", []))
    preferred = original[1:-1] if len(original) >= 3 else None
    primary = preferred if preferred in paths else paths[0]
    return {
        **product,
        "source_breadcrumbs": original,
        "category_paths": paths,
        "category_source_urls": discovered["category_source_urls"],
        "breadcrumbs": ["Strona główna", *primary, product["name"]],
    }
