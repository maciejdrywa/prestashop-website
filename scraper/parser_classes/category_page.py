from pydantic import BaseModel, Field
from typing import Optional,List

class CategoryLink(BaseModel):
    """Przechowuje nazwę kategorii i jej źródłowy adres."""
    name: str
    url: str


class CategoryPage(BaseModel):
    """Przechowuje produkty, podkategorie i paginację odczytanej strony kategorii."""
    product_urls: List[str]
    next_page_url: Optional[str] = None
    subcategories: List[CategoryLink] = Field(default_factory=list)
    total_products: Optional[int] = None


# Alias zachowuje dotychczasowy interfejs parsera.
category_page = CategoryPage
