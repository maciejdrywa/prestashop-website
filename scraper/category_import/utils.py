from __future__ import annotations

import re
import unicodedata
from typing import Any

# Tablica transliteracji polskich znaków dla link_rewrite (slug)
PL_CHAR_TRANSLATION = str.maketrans({
    "ą": "a", "ć": "c", "ę": "e", "ł": "l", "ń": "n", "ó": "o", "ś": "s", "ź": "z", "ż": "z",
    "Ą": "a", "Ć": "c", "Ę": "e", "Ł": "l", "Ń": "n", "Ó": "o", "Ś": "s", "Ź": "z", "Ż": "z",
})


def slugify(text: str) -> str:
    """
    Tworzy bezpieczny, czytelny slug dla pola link_rewrite w PrestaShop.
    Transliteruje polskie znaki, usuwa znaki niedozwolone, zamienia spacje na myślniki
    oraz obcina wynik do 128 znaków (limit PrestaShop).
    """
    if not text:
        return "kategoria"
    text = text.translate(PL_CHAR_TRANSLATION)
    text = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode("ascii")
    text = text.lower()
    text = re.sub(r"[^a-z0-9]+", "-", text)
    slug = text.strip("-")
    return slug[:128] if slug else "kategoria"


def _extract_category_name(name_val: Any) -> str:
    """Pomocnicze wyciąganie nazwy lub wartości tekstowej pola z odpowiedzi PrestaShop (zarówno string jak i wielojęzyczne)."""
    if isinstance(name_val, str):
        return name_val.strip()
    if isinstance(name_val, list):
        for item in name_val:
            if isinstance(item, dict):
                if "value" in item and item["value"]:
                    return str(item["value"]).strip()
                if "#text" in item and item["#text"]:
                    return str(item["#text"]).strip()
    if isinstance(name_val, dict):
        if "value" in name_val and name_val["value"]:
            return str(name_val["value"]).strip()
        if "#text" in name_val and name_val["#text"]:
            return str(name_val["#text"]).strip()
        for v in name_val.values():
            if v:
                return str(v).strip()
    return ""
