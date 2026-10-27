# Twoje Drzwi do Sukcesu

Projekt realizowany w ramach przedmiotu **Biznes elektroniczny** na Politechnice Gdańskiej.

## Odwzorowywany sklep

Budujemy sklep internetowy na podstawie sklepu **Pan Domu**:
https://pandomu.com.pl/

## Cel projektu

Celem projektu jest odtworzenie sklepu internetowego z wykorzystaniem PrestaShop 8.x, uruchamianego w kontenerach Docker.

Zakres pierwszego etapu obejmuje:
- Pobranie kategorii, danych produktów i zdjęć ze sklepu źródłowego.
- Przygotowanie środowiska oraz inicjalizacji danych sklepu.
- Konfigurację katalogu, kont klientów, koszyka, płatności i dostawy.
- Zapewnienie HTTPS oraz obsługi poczty testowej.
- Automatyczne testy procesu zakupowego w Playwright.
- Pracę zespołową z wykorzystaniem GitHub Issues, osobnych gałęzi i recenzowanych Pull Requestów.

Projekt ma charakter dydaktyczny. Sklep z pobranymi danymi nie jest przeznaczony do publicznego udostępniania ani prowadzenia rzeczywistej sprzedaży.

## Skład zespołu

| Imię i nazwisko | Numer indeksu |
|---|---|
| Maciej Drywa | s203556 |
| Paweł Krajewski | s203918 |
| Wiktor Adamczyk | s203519 |

## Struktura repozytorium

| Katalog | Zawartość |
|---|---|
| shop/ | Kod sklepu, motyw potomny, własne moduły i Dockerfile |
| scraper/ | Kod scrapera i testy jednostkowe parsowania |
| scraper-results/ | Wyniki scrapowania i przykładowe zdjęcia |
| tests/ | Testy E2E w Playwright |
| deploy/ | Konfiguracja kontenerów i skrypty obsługi środowiska |
| docs/ | Dokumentacja, dziennik AI i decyzje architektoniczne |

## Uruchomienie

Instrukcja uruchomienia środowiska, inicjalizacji danych i wykonania testów zostanie uzupełniona wraz z implementacją.