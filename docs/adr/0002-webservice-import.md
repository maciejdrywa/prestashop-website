# ADR 0002 — Import katalogu przez Webservice API

## Kontekst

Eksport scrapera zawiera produkty, kategorie, kombinacje i galerie wymagające odwzorowania w PrestaShop. Import musi pozwalać na wznowienie po błędzie i ponowne wykonanie bez tworzenia duplikatów. Projekt posiada również historyczny importer PHP uruchamiany wewnątrz sklepu. Potrzebny był jeden domyślny sposób zapisu katalogu, aby oba mechanizmy nie zmieniały tych samych danych niezależnie.

## Rozważane opcje

1. Zapisywać katalog bezpośrednio do tabel MariaDB, samodzielnie utrzymując zależności i integralność danych.
2. Importować go wyłącznie skryptami PHP korzystającymi z natywnych klas PrestaShop.
3. Używać importera Python komunikującego się z Webservice API, pozostawiając konfigurację instalacji po stronie PHP.

## Decyzja

Domyślny przepływ `scraper/import_catalog.py` importuje katalog przez Webservice API. Python odpowiada za orkiestrację, mapowanie danych, stan wznowienia i raporty. Konfiguratory PHP przygotowują oraz finalizują sklep. Historyczny backend PHP pozostaje dostępny osobno; mechanizmy inicjalizacji ograniczają przypadkowe mieszanie obu sposobów importu.

## Konsekwencje

- Importer korzysta z interfejsu sklepu i nie musi bezpośrednio odtwarzać całego schematu jego tabel.
- Operacje sieciowe są wolniejsze od masowego zapisu SQL i wymagają obsługi uprawnień, formatów odpowiedzi, limitów czasu oraz błędów częściowych.
- Wznowienie wymaga trwałych mapowań i plików stanu. Utrata odpowiedzi nie może automatycznie oznaczać ponownego utworzenia obiektu.
- Konfiguracja sklepu i import katalogu mają odrębne odpowiedzialności; pełne uruchomienie nadal wymaga obu etapów.
- Zachowanie należy weryfikować z rzeczywistą wersją API PrestaShop, ponieważ same atrapy odpowiedzi nie potwierdzają zgodności integracji.

[Import katalogu](../CATALOG_IMPORT.md) · [Webservice API](../WEBSERVICE.md) · [Lista ADR](README.md)
