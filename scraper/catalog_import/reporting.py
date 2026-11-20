"""Podsumowanie potwierdzonych zapisów i odrzuconych rekordów bieżącego przebiegu."""


def catalog_summary(stages):
    results = {name: stage.get("result", {}) for name, stage in stages.items()}
    categories = results.get("categories", {})
    products = results.get("products", {})
    resources = products.get("resources", {})
    counts = {
        "categories": {**categories, "rejected": categories.get("errors", 0)},
        "products": {
            **resources.get("products", {}),
            "rejected": len(products.get("errors", [])),
        },
        "combinations": resources.get("combinations", {}),
        "images": results.get("images", {}).get("images", {}),
    }
    return {
        resource: {key: value.get(key, 0) for key in ("created", "updated", "rejected")}
        for resource, value in counts.items()
    }
