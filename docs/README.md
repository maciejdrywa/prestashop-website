# Dokumentacja projektu

Zacznij od [głównego README](../README.md): opisu projektu, pierwszego uruchomienia i podstawowych poleceń testów. Wszystkie polecenia w instrukcjach wykonuj z katalogu głównego repozytorium, chyba że wskazano inaczej.

## Instrukcje techniczne

| Dokument | Zakres |
|---|---|
| [Architektura](ARCHITECTURE.md) | Komponenty, kontenery, zależności i przepływ danych |
| [Decyzje architektoniczne (ADR)](adr/README.md) | Kontekst, rozważane opcje, decyzje i konsekwencje rozwiązań |
| [Wdrożenie](DEPLOYMENT.md) | Wymagania, `.env`, Compose, HTTPS, platformy i zatrzymanie |
| [Sklep](SHOP.md) | Obraz PrestaShop, motyw potomny, moduły i aktualizacja wyglądu |
| [Konfiguracja](CONFIGURATION.md) | Sprzedaż, dostawy, faktury, poczta, treści i eksport ustawień |
| [Scraper](SCRAPER.md) | Pobieranie, parsery, CLI, wznowienie i odświeżanie danych |
| [Dane katalogu](DATA.md) | Pliki wynikowe, kontrakt produktu, zdjęcia, próbki i Releases |
| [Inicjalizacja](INITIALIZATION.md) | Etapy PHP, mapa skryptów, dziennik i wznowienie |
| [Webservice API](WEBSERVICE.md) | Uprawnienia, zmienne środowiskowe i klient Python |
| [Import katalogu](CATALOG_IMPORT.md) | Wspólna komenda API, bootstrap, synchronizacja, aktywacja i benchmark |
| [Import produktów](PRODUCT_IMPORT.md) | Kategorie, produkty, cechy, kombinacje i magazyn |
| [Import zdjęć](IMAGE_IMPORT.md) | Cache, normalizacja, galerie i wznowienie uploadu |
| [Testowanie](TESTING.md) | Zależności, testy jednostkowe, integracyjne, E2E, PHP, JavaScript, raporty i CI |
| [Kopie i odtwarzanie](BACKUP.md) | Tworzenie kopii, odtwarzanie i weryfikacja na osobnej instancji |
| [Rozwiązywanie problemów](TROUBLESHOOTING.md) | Diagnoza uruchomienia i importu |

## Raporty, historia i materiały referencyjne

Poniższe materiały opisują konkretne przebiegi lub historię pracy. Ich obecność nie oznacza ponownej weryfikacji aktualnej wersji projektu.

| Materiał | Zakres |
|---|---|
| [Wyniki E2E](E2E_RESULTS.md) | Zapisane wyniki kontroli scenariusza zakupowego |
| [Weryfikacja odtwarzania](RESTORE_VERIFICATION.md) | Wynik zapisanego przebiegu odtwarzania |
| [Audyt](AUDIT.md) | Punkt wyjścia, zmiany i ograniczenia weryfikacji |
| [Materiały audytowe](audits/README.md) | Szczegółowe analizy i raporty |
| [Referencje wyglądu](design-reference/) | Materiały do odwzorowania sklepu źródłowego |
| [Dziennik AI](AI_LOG.md) | Historia użycia AI i sposób weryfikacji wyników |

Szczegółowe instrukcje są utrzymywane w `docs/`. README w katalogach komponentów pełnią rolę krótkich odsyłaczy.
