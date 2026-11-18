from __future__ import annotations

from dataclasses import dataclass, field
from decimal import Decimal
from typing import Any, Dict, List, Optional


@dataclass
class VariantItem:
    """Reprezentacja pojedynczego wariantu/kombinacji produktu."""
    variant_id: str
    name: str
    attributes: Dict[str, str] = field(default_factory=dict)
    price_modifier: Decimal = Decimal("0.000000")
    price_netto: Decimal = Decimal("0.000000")
    price_brutto: Decimal = Decimal("0.00")
    stock: Optional[int] = None
    raw_data: Dict[str, Any] = field(default_factory=dict)

    def combination_reference(self, product_id: str) -> str:
        """Deterministyczna referencja kombinacji w formacie PrestaShop."""
        return f"pandomu-{product_id}-combination-{self.variant_id}"


@dataclass
class ProductItem:
    """Reprezentacja pojedynczego produktu z pliku products.json."""
    product_id: str
    name: str
    description: str
    description_short: str
    price_netto: Decimal
    price_brutto: Decimal
    vat_rate: Decimal
    weight: Decimal
    weight_origin: str = "source"  # "source" lub "demo"
    images: List[str] = field(default_factory=list)
    cover_image: Optional[str] = None
    variants: List[VariantItem] = field(default_factory=list)
    stock: Optional[int] = None
    category_paths: List[List[str]] = field(default_factory=list)
    category_source_urls: List[str] = field(default_factory=list)
    breadcrumbs: List[str] = field(default_factory=list)
    product_url: Optional[str] = None
    features: Dict[str, str] = field(default_factory=dict)
    raw_data: Dict[str, Any] = field(default_factory=dict)

    @property
    def reference(self) -> str:
        """Deterministyczna referencja produktu w formacie PrestaShop."""
        return f"pandomu-{self.product_id}"

    @property
    def has_variants(self) -> bool:
        """Czy produkt posiada zdefiniowane warianty/kombinacje."""
        return len(self.variants) > 0


@dataclass
class ProductImportStats:
    """Statystyki procesu importu produktów i kombinacji."""
    total: int = 0
    created: int = 0
    updated: int = 0
    skipped: int = 0
    errors: int = 0
    combinations_created: int = 0
    combinations_updated: int = 0
    combinations_skipped: int = 0
    stocks_updated: int = 0
    stocks_skipped: int = 0
    error_details: List[str] = field(default_factory=list)
