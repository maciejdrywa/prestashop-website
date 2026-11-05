from parse_category import parse_category


def test_parse_category_offline():
    fake_category_html = """
    <html>
        <body>
            <div class="products row">
                <article class="product-miniature">
                    <a class="thumbnail product-thumbnail" href="https://pandomu.com.pl/klamki/1.html">
                        Klamka 1
                    </a>
                </article>
                <article class="product-miniature">
                    <a class="thumbnail product-thumbnail" href="https://pandomu.com.pl/klamki/2.html">
                        Klamka 2
                    </a>
                </article>
            </div>

            <ul class="page-list">
                <li>
                    <a rel="next js-search-link" href="https://pandomu.com.pl/klamki?page=2">
                        Następna
                    </a>
                </li>
            </ul>
        </body>
    </html>
    """

    base_url = "https://pandomu.com.pl/klamki"
    category_data = parse_category(fake_category_html, base_url)

    assert category_data is not None, "Błąd: Parser zwrócił None. Prawdopodobnie błąd Pydantic!"

    assert len(category_data.product_urls) == 2, f"Oczekiwano 2 linkow, wyciągnięto {len(category_data.product_urls)}"
    assert category_data.product_urls[0] == "https://pandomu.com.pl/klamki/1.html"
    assert category_data.next_page_url == "https://pandomu.com.pl/klamki?page=2", "Nie wykryto poprawnego linku do następnej strony!"

    print("Sukces! Parser kategorii działa poprawnie.")