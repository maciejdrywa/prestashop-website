from bs4 import BeautifulSoup
from typing import List

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
