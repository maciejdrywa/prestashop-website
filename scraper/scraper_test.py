from pathlib import Path
from scraper_parser import parse_product

HTML_DIR = Path(__file__).parent / "html_files_and_robots"

#Podstawowy test

def test_parse_1():
    file_path = HTML_DIR / "Test1.html"
    with open(file_path, "r", encoding="utf-8") as f:
        html = f.read()

    product = parse_product(html, "https://pandomu.com.pl/test-klodka-okragla")

    assert product is not None, "Parser zwrócił None dla Kłódki okrągłej!"
    assert product.product_id == "359"
    assert product.name == "Kłódka lob okrągła szyfrowa"
    assert product.price_brutto == 22.99
    assert product.weight == 0.49
    assert len(product.images) >= 1

# warianty

def test_parse_2():
    file_path = HTML_DIR / "Test3.html"
    with open(file_path, "r", encoding="utf-8") as f:
        html = f.read()

    product = parse_product(html, "https://pandomu.com.pl/test-zawias")

    assert product is not None, "Parser zwrócił None dla Zawiasu!"
    assert product.product_id == "815"
    assert product.name == "Zawias niewidoczny 111x29 do drzwi bezprzylgowych"
    assert product.price_brutto == 61.11
    assert product.weight == 0.3
    assert len(product.variants) == 2

#Brak wariantow

def test_parse_3():
    file_path = HTML_DIR / "Test2.html"
    with open(file_path, "r", encoding="utf-8") as f:
        html = f.read()

    product = parse_product(html, "https://pandomu.com.pl/test-klodka-trzpieniowa")

    assert product is not None, "Parser zwrócił None dla Kłódki trzpieniowej bez wagi!"
    assert product.product_id == "366"

# jedno zdjecie

def test_parse_4():
        file_path = HTML_DIR / "Test4.html"
        with open(file_path, "r", encoding="utf-8") as f:
            html = f.read()

        product = parse_product(html, "https://pandomu.com.pl/test-klodka-trzpieniowa")

        assert product is not None, "Parser zwrócił None dla Kłódki trzpieniowej bez wagi!"
        assert product.product_id == "912"
        assert product.images is not None