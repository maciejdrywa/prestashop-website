"""Walidacja galerii; jedyny zatwierdzony wyjątek to źródłowy placeholder 539."""

import json
from pathlib import Path

from .cache import filename, source_url

PLACEHOLDER = "https://pandomu.com.pl/img/p/pl-default-medium_default.jpg"


def read_images(path):
    rows = json.loads(Path(path).read_text(encoding="utf-8"))
    if not isinstance(rows, list) or not rows:
        raise ValueError("Brak listy produktów.")
    seen, filenames, plans = set(), {}, []
    for row in rows:
        if not isinstance(row, dict):
            raise ValueError("Niepoprawny produkt.")
        sid = str(row.get("product_id", ""))
        if not sid.isdigit() or int(sid) <= 0 or sid in seen:
            raise ValueError("Powielone lub niepoprawne źródłowe ID produktu.")
        seen.add(sid)
        images = row.get("images")
        if (
            not isinstance(images, list)
            or any(not isinstance(url, str) for url in images)
            or len(images) != len(set(images))
        ):
            raise ValueError(f"Niepoprawna galeria {sid}.")
        kind = "product"
        if not images:
            explicit = row.get("source_placeholder")
            if sid != "539" or (explicit is not None and explicit != PLACEHOLDER):
                raise ValueError(f"Produkt {sid} nie ma potwierdzonego zdjęcia.")
            images, kind = [PLACEHOLDER], "source_placeholder"
        cover = row.get("cover_image") or (images[0] if len(images) == 1 else None)
        if cover not in images:
            raise ValueError(f"Brak jednoznacznej okładki {sid}.")
        for url in images:
            source_url(url)
            name = filename(url)
            if name in filenames and filenames[name] != url:
                raise ValueError("Różne URL mają tę samą nazwę cache.")
            filenames[name] = url
        plans.append(
            {
                "source_id": sid,
                "reference": "pandomu-" + sid,
                "urls": images,
                "cover": cover,
                "kind": kind,
            }
        )
    return plans
