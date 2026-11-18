from __future__ import annotations

import hashlib
import math
from typing import Dict, List, Optional, Tuple

INITIAL_STOCK_MIN = 0
INITIAL_STOCK_MAX = 10
INITIAL_UNAVAILABLE_MIN_RATIO = 0.05
INITIAL_UNAVAILABLE_MAX_RATIO = 0.20
INITIAL_UNAVAILABLE_TARGET_RATIO = 0.10

# PrestaShop: out_of_stock = 0 oznacza odmowę zamówień przy braku towaru
DEFAULT_OUT_OF_STOCK = 0


def validate_stock_quantity(quantity: Optional[int], label: str = "stan magazynowy") -> int:
    """Waliduje czy stan magazynowy mieści się w dopuszczalnym zakresie 0-10 sztuk."""
    if quantity is None:
        raise ValueError(f"Brak wartości dla {label}.")
    if not isinstance(quantity, int) or isinstance(quantity, bool):
        try:
            quantity = int(quantity)
        except (ValueError, TypeError) as exc:
            raise ValueError(f"Niepoprawny format liczbowy {label}: {quantity}") from exc
    if quantity < INITIAL_STOCK_MIN or quantity > INITIAL_STOCK_MAX:
        raise ValueError(
            f"Wartość {label} ({quantity}) poza dozwolonym zakresem [{INITIAL_STOCK_MIN}, {INITIAL_STOCK_MAX}]."
        )
    return quantity


def verify_stock_ratio(stock_values: List[int]) -> Tuple[bool, float]:
    """
    Weryfikuje twardy wymóg biznesowy:
    Odsetek produktów o stanie równym 0 musi mieścić się w przedziale 5% - 20%.
    Dla małych zbiorów (< 5 rekordów) weryfikacja dopuszcza brak zer ze względów arytmetycznych.
    Zwraca krotkę: (czy_spełnia, procent_zer).
    """
    if not stock_values:
        return True, 0.0
    zero_count = sum(1 for s in stock_values if s == 0)
    ratio = zero_count / len(stock_values)
    percentage = ratio * 100.0

    if len(stock_values) < 5:
        # Dla próbki poniżej 5 elementów matematycznie nie da się uzyskać 5-20%
        return True, percentage

    is_valid = INITIAL_UNAVAILABLE_MIN_RATIO <= ratio <= INITIAL_UNAVAILABLE_MAX_RATIO
    return is_valid, percentage


def generate_deterministic_stock_plan(product_ids: List[str]) -> Dict[str, int]:
    """
    Wyznacza deterministyczny plan stanów magazynowych 0–10 sztuk dla listy ID produktów,
    gwarantując około 10% produktów niedostępnych (w przedziale 5%–20%).
    
    Implementuje algorytm oparty na SHA-256 z deploy/init/lib/stock.php,
    zapewniając 100% powtarzalność niezależnie od kolejności wejścia.
    """

    count = len(product_ids)
    if count == 0:
        return {}

    minimum = math.ceil(count * INITIAL_UNAVAILABLE_MIN_RATIO)
    maximum = math.floor(count * INITIAL_UNAVAILABLE_MAX_RATIO)

    if minimum > maximum:
        # Dla bardzo małych próbek (np. test z --limit 3)
        zero_count = 0
    else:
        zero_count = max(minimum, min(maximum, round(count * INITIAL_UNAVAILABLE_TARGET_RATIO)))

    unique_ids = list(dict.fromkeys(str(pid) for pid in product_ids))

    def unavail_hash(pid: str) -> str:
        return hashlib.sha256(f"stock-v1:unavailable:{pid}".encode("utf-8")).hexdigest()

    # Ranking hashy wyznacza deterministycznie produkty z zerowym stanem
    sorted_ids = sorted(unique_ids, key=lambda pid: (unavail_hash(pid), pid))

    result: Dict[str, int] = {}
    for idx, pid in enumerate(sorted_ids):
        if idx < zero_count:
            result[pid] = 0
        else:
            q_hash = hashlib.sha256(f"stock-v1:quantity:{pid}".encode("utf-8")).hexdigest()[:8]
            # Zakres 1..10
            result[pid] = 1 + (int(q_hash, 16) % INITIAL_STOCK_MAX)

    return result


def distribute_stock_to_variants(total_stock: int, variant_ids: List[str]) -> Dict[str, int]:
    """
    Dzieli całkowity stan magazynowy produktu pomiędzy warianty,
    zachowując sumę równą total_stock.
    """
    v_count = len(variant_ids)
    if v_count == 0:
        return {}
    if total_stock < 0 or total_stock > INITIAL_STOCK_MAX:
        raise ValueError(f"Całkowity stan {total_stock} poza zakresem 0-{INITIAL_STOCK_MAX}.")

    sorted_vids = sorted(variant_ids, key=lambda vid: (int(vid) if vid.isdigit() else vid))
    base = total_stock // v_count
    remainder = total_stock % v_count

    result: Dict[str, int] = {}
    for idx, vid in enumerate(sorted_vids):
        result[vid] = base + (1 if idx < remainder else 0)

    return result
