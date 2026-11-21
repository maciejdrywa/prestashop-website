# ADR 0001 — Testy E2E w Pythonie zamiast TypeScript

## Kontekst

Pierwsza wersja testów E2E korzystała z Playwright i TypeScript. Scraper, importer API oraz część testów jednostkowych powstawały już w Pythonie. Utrzymywanie osobnego środowiska TypeScript do E2E zwiększało liczbę języków, narzędzi i konfiguracji, które musiał znać zespół. W projekcie grupowym utrudniało to przekazywanie zadań i wspólne diagnozowanie błędów.

## Rozważane opcje

1. Pozostawić E2E w TypeScript i utrzymywać oddzielny zestaw zależności oraz konfigurację uruchamiania.
2. Przepisać E2E na Python, zachowując Playwright jako narzędzie sterowania przeglądarką.
3. Utrzymywać równolegle oba zestawy, przenosząc scenariusze stopniowo bez ustalenia jednej wersji docelowej.

## Decyzja

Przepisano testy E2E z TypeScript na Python, aby ograniczyć liczbę języków i osobnych narzędzi w projekcie. Scenariusze znajdują się w `tests/e2e/`, wspólne funkcje w `tests/helpers/`, a konfiguracja pytest w `tests/conftest.py`. Zależności określa `tests/requirements.txt`; testy używają pytest i Playwright.

## Konsekwencje

- Zespół może rozwijać scraper, importer oraz testy przeglądarkowe w jednym języku i korzystać ze wspólnych narzędzi Pythona.
- Migracja wymagała przepisania scenariuszy i dostosowania konfiguracji; zgodność zachowania trzeba sprawdzać na działającym sklepie.
- Playwright nadal wymaga instalacji przeglądarek. Przejście na Python nie usuwa PHP i JavaScript używanych przez sklep ani potrzeby testowania ich działania.
- Aktualne instrukcje i rozwój scenariuszy odnoszą się do wersji Python, co ogranicza ryzyko rozbieżności między dwoma zestawami E2E.

[Instrukcja testów](../TESTING.md) · [Lista ADR](README.md)
