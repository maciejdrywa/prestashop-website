from pydantic import BaseModel, Field
from typing import Optional,List

class CategoryLink(BaseModel):
    name: str
    url: str


class category_page(BaseModel):
    product_urls: List[str]
    next_page_url: Optional[str] = None
    subcategories: List[CategoryLink] = Field(default_factory=list)
    total_products: Optional[int] = None
