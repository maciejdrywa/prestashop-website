from __future__ import annotations

from decimal import Decimal, ROUND_HALF_UP
from typing import Union

# PrestaShop Tax Rules Groups dla stawek polskich
# Zgodne z tabelą ps_tax_rules_group w PrestaShop
VAT_RATE_TO_TAX_RULES_GROUP: dict[Decimal, int] = {
    Decimal("23"): 1,
    Decimal("23.0"): 1,
    Decimal("8"): 2,
    Decimal("8.0"): 2,
    Decimal("5"): 3,
    Decimal("5.0"): 3,
    Decimal("0"): 4,
    Decimal("0.0"): 4,
}

# Precyzje zaokrągleń
NETTO_PRECISION = Decimal("0.000001")
GROSS_PRECISION = Decimal("0.01")


def to_decimal(value: Union[int, float, str, Decimal], label: str = "kwota") -> Decimal:
    """Konwertuje wartość na typ Decimal z walidacją poprawności numerycznej."""
    if value is None or isinstance(value, bool):
        raise ValueError(f"Niepoprawna wartość liczbowa dla {label}: {value}")
    try:
        dec = Decimal(str(value))
        if dec.is_nan() or dec.is_infinite():
            raise ValueError(f"Wartość {label} nie może być NaN ani nieskończonością: {value}")
        return dec
    except Exception as exc:
        raise ValueError(f"Błąd konwersji {label} na Decimal: {value} ({exc})") from exc


def round_netto(value: Union[int, float, str, Decimal]) -> Decimal:
    """Zaokrągla kwotę netto do 6 miejsc po przecinku (standard PrestaShop)."""
    return to_decimal(value, "cena netto").quantize(NETTO_PRECISION, rounding=ROUND_HALF_UP)


def round_gross(value: Union[int, float, str, Decimal]) -> Decimal:
    """Zaokrągla kwotę brutto do 2 miejsc po przecinku (grosze)."""
    return to_decimal(value, "cena brutto").quantize(GROSS_PRECISION, rounding=ROUND_HALF_UP)


def calculate_gross(netto: Union[int, float, str, Decimal], vat_rate: Union[int, float, str, Decimal]) -> Decimal:
    """Wylicza kwotę brutto na podstawie ceny netto i stawki VAT."""
    net_dec = to_decimal(netto, "cena netto")
    vat_dec = to_decimal(vat_rate, "stawka VAT")
    multiplier = Decimal("1") + (vat_dec / Decimal("100"))
    return (net_dec * multiplier).quantize(GROSS_PRECISION, rounding=ROUND_HALF_UP)


def calculate_netto(gross: Union[int, float, str, Decimal], vat_rate: Union[int, float, str, Decimal]) -> Decimal:
    """Wylicza kwotę netto na podstawie ceny brutto i stawki VAT."""
    gross_dec = to_decimal(gross, "cena brutto")
    vat_dec = to_decimal(vat_rate, "stawka VAT")
    divisor = Decimal("1") + (vat_dec / Decimal("100"))
    return (gross_dec / divisor).quantize(NETTO_PRECISION, rounding=ROUND_HALF_UP)


def get_tax_rules_group_id(vat_rate: Union[int, float, str, Decimal]) -> int:
    """Mapuje stawkę VAT na odpowiednie id_tax_rules_group w PrestaShop."""
    vat_dec = to_decimal(vat_rate, "stawka VAT")
    group_id = VAT_RATE_TO_TAX_RULES_GROUP.get(vat_dec)
    if group_id is not None:
        return group_id
    
    # Próba normalizacji np. 23.00 -> 23
    normalized = vat_dec.normalize()
    group_id = VAT_RATE_TO_TAX_RULES_GROUP.get(normalized)
    if group_id is not None:
        return group_id

    raise ValueError(f"Nieobsługiwana stawka VAT: {vat_rate}%. Dostępne reguły: 23%, 8%, 5%, 0%.")


def calculate_price_modifier(variant_netto: Union[int, float, str, Decimal], base_netto: Union[int, float, str, Decimal]) -> Decimal:
    """
    Wylicza modyfikator ceny netto wariantu (wpływ na cenę bazową w kombinacji PrestaShop).
    Może być dodatni, ujemny lub zerowy.
    """
    v_net = round_netto(variant_netto)
    b_net = round_netto(base_netto)
    return (v_net - b_net).quantize(NETTO_PRECISION, rounding=ROUND_HALF_UP)


def format_decimal(value: Decimal, places: int = 6) -> str:
    """Formatuje Decimal do ciągu znaków z dokładną liczbą miejsc po przecinku."""
    return f"{value:.{places}f}"
