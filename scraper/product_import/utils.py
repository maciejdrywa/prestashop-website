from __future__ import annotations

import re
import unicodedata

# Transliteracja polskich znaków diakrytycznych
PL_CHAR_TRANSLATION = str.maketrans({
    "ą": "a", "ć": "c", "ę": "e", "ł": "l", "ń": "n",
    "ó": "o", "ś": "s", "ź": "z", "ż": "z",
    "Ą": "a", "Ć": "c", "Ę": "e", "Ł": "l", "Ń": "n",
    "Ó": "o", "Ś": "s", "Ź": "z", "Ż": "z",
})


def slugify(text: str) -> str:
    """
    Generuje bezpieczny slug URL zgodny z link_rewrite w PrestaShop.
    Usuwa polskie znaki diakrytyczne, zamienia znaki specjalne na myślniki
    oraz ogranicza długość do 128 znaków.
    """
    if not text:
        return ""
    # Transliteracja polskich znaków
    clean = text.translate(PL_CHAR_TRANSLATION)
    # Normalizacja Unicode NFKD
    clean = unicodedata.normalize("NFKD", clean)
    clean = clean.encode("ascii", "ignore").decode("ascii")
    # Zamiana wszystkiego poza literami i cyframi na myślniki
    clean = re.sub(r"[^a-zA-Z0-9]+", "-", clean)
    clean = clean.strip("-").lower()
    return clean[:128]
