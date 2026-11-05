# Scraper

Katalog zawiera kod narzędzia pobierającego kategorie, podkategorie, dane produktów i zdjęcia ze sklepu źródłowego.

Scraper respektuje robots.txt, identyfikuje się nagłówkiem User-Agent i wykonuje maksymalnie 2 zapytania na sekundę. Obsługuje wznawianie pobierania i walidację danych.

Testy jednostkowe parsowania korzystają z lokalnych przykładowych stron HTML.

## Wyjątek od wymagań W3
Całkowita liczba produktów w asortymencie sklepu wynosi około 600