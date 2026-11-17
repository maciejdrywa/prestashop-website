# Wyniki scrapowania

`products.json` i `categories.json` przechowują eksport UTF-8 z adresami źródłowymi, a `scrape-report.json` raport kompletności. Format opisuje [instrukcja scrapera](../docs/SCRAPER.md).

Pełne zdjęcia JPEG są zapisywane na bieżąco w ignorowanym katalogu `images/`. Mają dłuższy bok 800–1600 px i maksymalnie 300000 bajtów. Do Git trafia maksymalnie 20 przykładów z `samples/`. Pełny zestaw oraz `product_images.zip` pozostają poza Git, a archiwum do 1 GB można publikować w GitHub Releases.

Inicjalizacja wymaga obu JSON-ów i lokalnego `images/`. Obecny produkt 539 nie ma galerii w źródle. Raport zachowuje to ograniczenie.
