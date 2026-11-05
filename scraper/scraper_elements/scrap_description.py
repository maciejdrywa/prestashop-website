from bs4 import BeautifulSoup

def scrap_description(soup: BeautifulSoup) -> str:
    long_desc = ""
    short_desc = ""

    long_description_tag = soup.find('div', id='description')
    if long_description_tag:
        long_desc = long_description_tag.decode_contents().strip()

    description_tag = soup.find('div', class_='product-description')
    if description_tag:
        short_desc = description_tag.decode_contents().strip()  # that extracts all contents of div



    if short_desc and long_desc:
        if short_desc != long_desc and short_desc not in long_desc:
            return f"<div class=\"product-short-desc\">{short_desc}</div>\n<div class=\"product-full-desc\">{long_desc}</div>"
        return long_desc
    elif long_desc:
        return long_desc
    elif short_desc:
        return short_desc

    raise ValueError("Could not find description")