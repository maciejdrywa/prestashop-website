"""Odczyt kwot PLN bez utraty dokładności obliczeń dziesiętnych."""

import re
from decimal import Decimal

PLN_AMOUNT_PATTERN = re.compile(
    r"^\s*(?:[0-9]+|[0-9]{1,3}(?:[ \u00a0\u202f][0-9]{3})+),[0-9]{2}\s*zł\s*$"
)


def parse_pln_amount(text: str) -> Decimal:
    """Sprawdza zapis kwoty w złotych i zamienia go na dokładną wartość dziesiętną."""
    assert PLN_AMOUNT_PATTERN.fullmatch(text), f"Invalid PLN amount: {text!r}"
    normalized = re.sub(r"\s+", "", text)
    return Decimal(normalized.removesuffix("zł").replace(",", "."))
