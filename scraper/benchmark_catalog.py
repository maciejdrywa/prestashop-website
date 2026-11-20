"""Osobny zestaw techniczny 1000 produktów. Nigdy nie nadpisuje eksportu scrapera."""

import argparse
import copy
import json
from pathlib import Path

from catalog_import.config import ROOT
from image_import.planning import PLACEHOLDER
from import_state import atomic_json


def generate(products_file, categories_file, output, count=1000):
    products = json.loads(products_file.read_text())
    categories = json.loads(categories_file.read_text())
    output = output.resolve()
    academic = (ROOT / "scraper-results").resolve()
    if output == academic or academic in output.parents or count < len(products):
        raise ValueError(
            "Benchmark wymaga osobnego katalogu poza scraper-results i co najmniej liczby produktów źródła."
        )
    if output.exists() and any(output.iterdir()):
        raise ValueError("Katalog benchmarku musi być pusty.")
    result = copy.deepcopy(products)
    # Pełny pierwszy zestaw zachowuje źródłowe ID wymagane przez konfiguratory promocji.
    for index in range(count - len(products)):
        source = copy.deepcopy(products[index % len(products)])
        sid = str(1_000_000 + index)
        source["product_id"] = sid
        source["product_url"] = f"https://benchmark.invalid/product-{sid}"
        source["name"] = ("Benchmark " + source["name"])[:128]
        source["breadcrumbs"][-1] = source["name"]
        if not source["images"]:
            source["images"] = [PLACEHOLDER]
            source["cover_image"] = PLACEHOLDER
        for offset, variant in enumerate(source["variants"]):
            variant["variant_id"] = str(100_000_000 + index * 10000 + offset)
        result.append(source)
    for category in categories:
        path = category["category_path"]
        category["product_ids"] = [
            p["product_id"]
            for p in result
            if any(pth[: len(path)] == path for pth in p["category_paths"])
        ]
    output.mkdir(parents=True, exist_ok=True)
    atomic_json(output / "products.json", result)
    atomic_json(output / "categories.json", categories)
    summary = {
        "purpose": "benchmark-only",
        "products": len(result),
        "combinations": sum(len(p["variants"]) for p in result),
        "images": sum(len(p["images"]) or 1 for p in result),
        "source_products": len(products),
    }
    atomic_json(output / "benchmark.json", summary)
    return summary


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--count", type=int, default=1000)
    parser.add_argument(
        "--products-file", type=Path, default=ROOT / "scraper-results/products.json"
    )
    parser.add_argument(
        "--categories-file", type=Path, default=ROOT / "scraper-results/categories.json"
    )
    args = parser.parse_args()
    print(
        json.dumps(
            generate(
                args.products_file, args.categories_file, args.output_dir, args.count
            ),
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
