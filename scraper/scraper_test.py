from scraper_parser import parse_product


def test_wyciagania_id_produktu():
    # 1. Przygotowujemy sztuczny, minimalny kod HTML (z Twoimi klasami)
    fake_html = """
    <html>
        <head>
        <meta property="product:pretax_price:amount" content="18.691057">
        <meta property="product:price:amount" content="22.99">
        <meta property="product:weight:value" content="0.490000">
        
        <body class="lang-pl country-pl currency-pln layout-full-width page-product tax-display-enabled product-id-359 product-klodka-lob-okragla-szyfrowa product-id-category-48">
            <h1>Kłódka lob okrągła szyfrowa</h1>
               <div class="js-qv-mask mask">
      <ul class="product-images js-qv-product-images">
                  <li class="thumb-container js-thumb-container">
            <img class="thumb js-thumb  selected js-thumb-selected " data-image-medium-src="https://pandomu.com.pl/900-medium_default/klodka-lob-okragla-szyfrowa.jpg" data-image-large-src="https://pandomu.com.pl/900-large_default/klodka-lob-okragla-szyfrowa.jpg" src="K%C5%82%C3%B3dka%20lob%20okr%C4%85g%C5%82a%20szyfrowa,%20k%C5%82%C3%B3dka%20KOSY,%20k%C5%82%C3%B3dki%20lob%20-%20PAN%20DOMU_files/klodka-lob-okragla-szyfrowa_BVIH.jpg" alt="Kłódka lob okrągła szyfrowa" title="Kłódka lob okrągła szyfrowa" loading="lazy" width="98" height="98">
          </li>
                  <li class="thumb-container js-thumb-container">
            <img class="thumb js-thumb " data-image-medium-src="https://pandomu.com.pl/901-medium_default/klodka-lob-okragla-szyfrowa.jpg" data-image-large-src="https://pandomu.com.pl/901-large_default/klodka-lob-okragla-szyfrowa.jpg" src="K%C5%82%C3%B3dka%20lob%20okr%C4%85g%C5%82a%20szyfrowa,%20k%C5%82%C3%B3dka%20KOSY,%20k%C5%82%C3%B3dki%20lob%20-%20PAN%20DOMU_files/klodka-lob-okragla-szyfrowa_003_BVIH.jpg" alt="Kłódka lob okrągła szyfrowa" title="Kłódka lob okrągła szyfrowa" loading="lazy" width="98" height="98">
          </li>
              </ul>
    </div>
                  <div id="product-description-short-359" class="product-description"><p><strong>PRZEZNACZENIE:</strong>&nbsp;&nbsp;kłódka
 okrągła szyfrowa , uniwersalna, dostępna w czterech &nbsp;kolorach 
&nbsp;fioletowy, niebieski, zielony, czerwony. Kłódka 
posiada&nbsp;&nbsp;hartowany pałąk. Zamykana na szyfr podany na odwrocie
 kłódki.&nbsp;</p>
<p><strong>WYMIARY:</strong>&nbsp;przedstawione na rysunku technicznym</p>
<p><strong>PRODUCENT:</strong>&nbsp;LOB&nbsp;</p></div>
    <div>
                <nav data-depth="5" class="breadcrumb hidden-sm-down">
  <ol>
    
              
          <li>
                          <a href="https://pandomu.com.pl/"><span>Strona główna</span></a>
                      </li>
        
              
          <li>
                          <a href="https://pandomu.com.pl/12-okucia-drzwiowe"><span>Okucia drzwiowe</span></a>
                      </li>
        
              
          <li>
                          <a href="https://pandomu.com.pl/21-klodki"><span>kłódki </span></a>
                      </li>
        
              
          <li>
                          <a href="https://pandomu.com.pl/48-klodki-szyfrowe"><span>kłódki szyfrowe</span></a>
                      </li>
        
              
          <li>
                          <span>Kłódka lob okrągła szyfrowa</span>
                      </li>
        
          
  </ol>
</nav>
</div>
<div class="product-variants js-product-variants">
          <div class="clearfix product-variants-item">
      <span class="control-label">WYBIERZ WERSJĘ: 
                      PRAWA                                      </span>
              <select class="form-control form-control-select" id="group_4" aria-label="WYBIERZ WERSJĘ" data-product-attribute="4" name="group[4]">
                      <option value="25" title="PRAWA" selected="selected">PRAWA</option>
                      <option value="26" title="LEWA">LEWA</option>
                  </select>
          </div>
      </div>    
        </body>
    </html>
    """

    # 2. Odpalamy Twoją funkcję parsującą
    test_url = "https://pandomu.com.pl/test"
    product = parse_product(fake_html, test_url)

    # 3. Sprawdzamy, czy funkcja zwróciła to, czego oczekujemy (Asercje)
    assert product is not None, "Błąd: Parser zwrócił None, prawdopodobnie schemat Pydantic odrzucił dane!"
    assert product.product_id == "359", f"Błąd: Oczekiwano ID '359', a parser znalazł '{product.product_id}'"

    print("Sukces! Parser poprawnie wyciągnął ID produktu.")