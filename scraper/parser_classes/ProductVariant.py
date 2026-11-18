from typing import Dict

from pydantic import BaseModel


class ProductVariant(BaseModel):
    """Opisuje rzeczywistą kombinację atrybutów z cenami i modyfikatorem netto."""
    variant_id: str
    name: str
    attributes: Dict[str, str]
    price_modifier: float
    price_netto: float
    price_brutto: float
    stock: int
