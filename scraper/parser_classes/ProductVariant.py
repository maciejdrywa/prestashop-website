from pydantic import BaseModel
from typing import Dict

class ProductVariant(BaseModel):
    variant_id: str
    name: str
    attributes: Dict[str, str]
    price_modifier: float
    stock: int
