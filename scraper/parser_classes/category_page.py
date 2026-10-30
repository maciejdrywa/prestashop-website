from pydantic import BaseModel
from typing import Optional,List

class category_page(BaseModel):
    product_urls: List[str]
    next_page_url: Optional[str] = None