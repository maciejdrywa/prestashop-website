from typing import List, Optional

from parser_classes.ProductVariant import ProductVariant
from pydantic import BaseModel, Field


class Product(BaseModel):
    """Opisuje eksport produktu zgodny z kontraktem importera PrestaShop."""
    product_id: str
    product_url: str
    name: str
    description: str
    description_short: str = ""
    breadcrumbs: List[str]
    category_paths: List[List[str]] = Field(default_factory=list)
    category_source_urls: List[str] = Field(default_factory=list)
    price_brutto: float
    price_netto: float
    vat_rate: float
    weight: Optional[float] = None
    images: List[str]
    cover_image: Optional[str] = None
    variants: List[ProductVariant] = Field(default_factory=list)
    stock: Optional[int] = None
