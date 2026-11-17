# Scraper

`main_scraper.py` pobiera katalog, pełne przypisania kategorii, opisy, galerie i zweryfikowane ceny kombinacji. `scrapping_images_module.py` pobiera lokalne JPEG, uzupełnia maksymalnie 20 próbek i przygotowuje archiwum.

```powershell
.\venv\Scripts\python.exe scraper/main_scraper.py
.\venv\Scripts\python.exe scraper/scrapping_images_module.py
```

Instalację zależności, opcje, wznawianie, profile przeglądarki i kontrakt JSON opisuje [instrukcja scrapera](../docs/SCRAPER.md). Testy lokalnych danych opisuje [instrukcja testów](../tests/README.md).

`Scrapping_products/` zawiera crawler i odkrywanie kombinacji, `scraper_elements/` parsery pól, `parser_classes/` modele Pydantic, a `scrapping_images/` normalizację i pakowanie. Dotychczasowe nazwy plików i aliasy klas pozostają zgodne z istniejącymi wywołaniami.

Asortyment źródłowego sklepu wynosi około 600 produktów. Aktualny eksport zawiera 574. Wymaganie większej liczby produktów wymaga potwierdzenia wyjątku zgodnie z ustaleniami zespołu.
