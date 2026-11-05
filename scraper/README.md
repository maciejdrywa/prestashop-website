# Scraper

Katalog zawiera kod narzędzia pobierającego kategorie, podkategorie, dane produktów i zdjęcia ze sklepu źródłowego.

Scraper respektuje robots.txt, identyfikuje się nagłówkiem User-Agent i wykonuje maksymalnie 2 zapytania na sekundę. Obsługuje wznawianie pobierania i walidację danych.

Testy jednostkowe parsowania korzystają z lokalnych przykładowych stron HTML.

## Wyjątek od wymagań W3
Całkowita liczba produktów w asortymencie sklepu wynosi około 600


W2.2

Scraper-parser.py jest glownym plikiem, ktory w tym momencie przechwytuje to co mamy na jakims fake-html.
Wyciagniete powinny byc katalog,descriptiom.id,images,name,pretax-price,price,variants,weight

Wszystkie elementy scrapowane maja osobny plik w folderze scraper-elements

parser-classes jest katalogiem z klasami product, product_variant i category_page

scraper-test jest programem testujacym, ktore sprawdza przykladowe scrapowanie produktu dla fake-html
odpalamy scraper-test przez komende: 

```pytest scraper/scraper.test.py```

test_category_scraper.py jest testem sprawdzajacym scrapowanie kategorii z pyrzkladowego fake-html
odpalamy test_category_parser przez: 

```pytest scraper/test_category_parser.py```

plan.json przedstawia przykladowy wyglad produktow, ktore beda zescrapowane w formacie json

W2.3
Dodanie main_scraper.py

Poczatkowe wyciaganie produktow ze stron, ktore dziala. 

Aby odpalic kod nalezy podac swoje cookies wygenerowane na stronie pandomu.com.pl w main_scraper.py w linii 34

``` cookies = "" ```

a nastepnie odpalic kod komenda:

```python main_scraper.py ```

Poczatkowo playwright jest ustawiony w headless, zeby bylo widac czy wszystko dziala i czy strony sie odpalaja

Scraper powinien tez pobierac produkty z scraper-results/products.json i nie wchodzic na strony, ktore sa juz zescrapowane (scraper bedzie wiedzial, ze produkt byl juz zesrapowany, kiedy zacznie go analizowac)

categories w main_scraper.py pozwala przejsc przez wszystkie ogolne kategorie, ktore sa na stronie (np klamki do drzwi czy okucia drzwiowe), ale paginacja juz jest zrobiona w kodzie (jezeli istnieje nastepna strona to po prostu na nia wchodzimy, a nie podajemy ja w kategories)

