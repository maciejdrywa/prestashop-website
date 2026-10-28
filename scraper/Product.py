from pydantic import BaseModel
from typing import List, Dict, Optional
from ProductVariant import ProductVariant

class Product(BaseModel):
    product_id: str
    product_url: str
    name: str
    description: str
    breadcrumbs: List[str]
    price_brutto: float
    price_netto: float
    vat_rate: float
    weight: Optional[float] = None
    images: List[str]
    variants: List[ProductVariant] = []