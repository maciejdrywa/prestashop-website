from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional, Tuple

# ID głównej kategorii bazowej "Strona główna" w PrestaShop
PRESTASHOP_HOME_CATEGORY_ID: int = 2


@dataclass
class CategoryItem:
    """Reprezentacja pojedynczej kategorii z pliku źródłowego categories.json."""
    name: str
    category_path: List[str]
    source_url: str
    parent_source_url: Optional[str] = None
    product_ids: List[str] = field(default_factory=list)
    raw_data: Dict[str, Any] = field(default_factory=dict)

    @property
    def depth(self) -> int:
        """Głębokość zagnieżdżenia kategorii w drzewie (1 = kategoria główna)."""
        return len(self.category_path)

    @property
    def path_key(self) -> Tuple[str, ...]:
        """Krotka nazw ścieżki kategorii."""
        return tuple(self.category_path)

    @property
    def formatted_path(self) -> str:
        """Czytelna tekstowa reprezentacja ścieżki kategorii (separator ' > ')."""
        return " > ".join(self.category_path)


@dataclass
class ImportStats:
    """Statystyki procesu importu kategorii."""
    total: int = 0
    created: int = 0
    updated: int = 0
    skipped: int = 0
    errors: int = 0
    error_details: List[str] = field(default_factory=list)
