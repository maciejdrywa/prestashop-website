from bs4 import BeautifulSoup
from typing import List

def scrap_images(soup: BeautifulSoup) -> List[str]:
    images = []

    images_tag = soup.find('ul', class_='product-images js-qv-product-images')

    if images_tag:
        images_all = images_tag.find_all('img')
        for image in images_all:
            images.append(image['data-image-large-src'])

    if not images:
        raise ValueError("Nie znaleziono zdjec")

    return images
