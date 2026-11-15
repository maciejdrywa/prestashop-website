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

## Zasady współpracy

Każdą zmianę realizujemy według procesu:

GitHub Issue → osobna gałąź → Pull Request → recenzja → scalenie do main.

1. **Issue** — tworzymy zadanie z opisem, kryteriami zakończenia i przypisaną osobą odpowiedzialną za wykonanie.
2. **Gałąź** — wykonawca tworzy osobną gałąź od aktualnego main, np. chore/1-repository-setup.
3. **Pull Request** — po wykonaniu zadania otwiera PR do main. Opisuje zmiany, sposób ich sprawdzenia i łączymy z PR z Issue.
4. **Recenzja** — inna osoba z zespołu sprawdza zmiany i dodaje merytoryczne komentarze. Autor wprowadza potrzebne poprawki.
5. **Scalenie** — po akceptacji i przejściu skonfigurowanych kontroli zmiany są scalane do main. Zadanie w Jirze zostaje oznaczone jako zakończone.

Nie wykonujemy bezpośrednich commitów ani pushów do main, poza commitem inicjalnym. Nie nadpisujemy historii main przez force push.

PR zawierające istotne fragmenty wygenerowane przez AI oznaczamy etykietą ai-assisted. W tym samym PR uzupełniamy docs/AI_LOG.md i opisujemy sposób weryfikacji wyników.

## Struktura repozytorium

| Katalog | Zawartość |
|---|---|
| shop/ | Kod sklepu, motyw potomny, własne moduły i Dockerfile |
| scraper/ | Kod scrapera i testy jednostkowe parsowania |
| scraper-results/ | Wyniki scrapowania i przykładowe zdjęcia |
| tests/ | Testy E2E w Playwright |
| deploy/ | Konfiguracja kontenerów i skrypty obsługi środowiska |
| docs/ | Dokumentacja, dziennik AI i decyzje architektoniczne |

## Technologie

Projekt wykorzystuje następujące technologie:

- **PrestaShop 8.2.8** – platforma e-commerce.
- **PHP** – język programowania wykorzystywany przez PrestaShop.
- **MariaDB 10.11.14** – relacyjna baza danych.
- **Docker** – konteneryzacja aplikacji.
- **Docker Compose v2** – zarządzanie środowiskiem kontenerowym.
- **Playwright** – automatyczne testy E2E.

## Uruchomienie

Instrukcja uruchomienia sklepu, przygotowania lokalnego certyfikatu
i weryfikacji HTTPS znajduje się w [deploy/README.md](deploy/README.md).