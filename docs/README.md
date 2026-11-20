# Dokumentacja projektu

| Dokument | Zakres |
|---|---|
| [Architektura](ARCHITECTURE.md) | Komponenty, zależności i przepływ danych |
| [Wdrożenie](DEPLOYMENT.md) | Wymagania, `.env`, Compose, HTTPS i platformy |
| [Inicjalizacja](INITIALIZATION.md) | Etapy, importery, dziennik i wznowienie |
| [Scraper](SCRAPER.md) | CLI, format danych, obrazy i testy Pythona |
| [Webservice API](WEBSERVICE.md) | Uprawnienia Least Privilege, zmienne środowiskowe i klient Python |
| [Import zdjęć D2.4](IMAGE_IMPORT.md) | Cache, normalizacja, galerie API i wznowienie uploadu |
| [Jedna komenda D2.5](CATALOG_IMPORT.md) | Bootstrap API, wznowienie, odbiór, aktywacja i benchmark |
| [Import produktów D2.3](PRODUCT_IMPORT.md) | Produkty, cechy, kombinacje, magazyn i wyniki testów API |
| [Konfiguracja](CONFIGURATION.md) | Sprzedaż, statusy, faktury, poczta, treści oraz eksport i odtwarzanie konfiguracji |
| [Kopie i odtwarzanie](../deploy/BACKUP.md) | `backup.sh`, `restore.sh` i weryfikacja odtwarzania `verify-restore.sh` |
| [Weryfikacja odtwarzania](RESTORE_VERIFICATION.md) | Wynik ostatniej weryfikacji: czas, kompletność danych, konfiguracja, funkcjonalności |
| [Rozwiązywanie problemów](TROUBLESHOOTING.md) | Diagnoza uruchomienia i importu |
| [Audyt](AUDIT.md) | Punkt wyjścia, usunięcia i weryfikacja zmian |
| [Dziennik AI](AI_LOG.md) | Dotychczasowa historia użycia AI |

Polecenia uruchamiamy z głównego katalogu repozytorium. Testy jednostkowe i integracyjne opisuje [katalog testów](../tests/README.md).
