from bs4 import BeautifulSoup
from typing import List
import json
import re
from urllib.parse import urlsplit

def scrap_images(soup: BeautifulSoup) -> List[str]:
    images = []

    images_tag = soup.find('ul', class_='product-images js-qv-product-images')

    if images_tag:
        images_all = images_tag.find_all('img')
        for image in images_all:
            url = image.get('data-image-large-src')
            if url and url not in images:
                images.append(url)

    # Some real source products have no gallery. Export the product with an
    # explicit empty list; do not drop it or treat the generic placeholder as a photo.
    return images


def scrap_cover_image(soup: BeautifulSoup, images: List[str]) -> str | None:
    """Resolve the source cover independently of gallery order and image size."""
    if not images:
        return None
    candidates = []
    details = soup.select_one("#product-details[data-product]")
    if details:
        data = json.loads(details["data-product"])
        cover = data.get("cover") or {}
        if cover.get("id_image") is not None:
            candidates.append(("id", str(cover["id_image"])))
        for size in ("large_default", "medium_default", "home_default"):
            url = cover.get("bySize", {}).get(size, {}).get("url")
            if url:
                candidates.append(("url", url))
    main = soup.select_one(".product-cover img")
    if main:
        for attribute in ("data-image-large-src", "src"):
            if main.get(attribute):
                candidates.append(("url", main[attribute]))
    meta = soup.select_one('meta[property="og:image"]')
    if meta and meta.get("content"):
        candidates.append(("url", meta["content"]))

    def image_id(url):
        match = re.search(r"/(\d+)(?:-[^/]*)?/", urlsplit(url).path)
        return match.group(1) if match else None

    for kind, candidate in candidates:
        if kind == "url" and candidate in images:
            return candidate
        identifier = candidate if kind == "id" else image_id(candidate)
        matches = [url for url in images if identifier and image_id(url) == identifier]
        if len(matches) == 1:
            return matches[0]
    if len(images) == 1:
        return images[0]
    raise ValueError("Nie znaleziono okladki zrodla wsrod zdjec galerii.")
