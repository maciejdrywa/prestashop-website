# ADR 0004 — Zdjęcia poza Git, lokalny cache i GitHub Releases

## Kontekst

Pełny katalog zawiera ponad tysiąc obrazów. Przechowywanie wszystkich zdjęć i ich kolejnych wersji w Git zwiększałoby rozmiar repozytorium. Jednocześnie uruchomienie sklepu po sklonowaniu nie powinno wymagać ponownego pobierania każdego zdjęcia ze sklepu źródłowego. Import musi sprawdzać jakość plików oraz pozwalać na wznowienie galerii.

## Rozważane opcje

1. Zapisywać wszystkie zdjęcia bezpośrednio w repozytorium Git.
2. Za każdym razem pobierać pełny zestaw ze źródła.
3. Utrzymywać lokalny cache oraz wersjonowane archiwum w GitHub Releases, ze źródłem jako opcją uzupełniającą.

## Decyzja

Pełne zdjęcia i ZIP pozostają poza Git. Importer najpierw sprawdza lokalny cache, następnie korzysta z przypiętego wydania `product-images-v1` w GitHub Releases, a brakujące obrazy pobiera ze źródła. Archiwum jest sprawdzane sumą SHA-256 i walidowane przed rozpakowaniem. Pobieranie ze źródła podlega wspólnej kontroli `robots.txt`.

Obrazy są normalizowane do JPEG zgodnie z wymaganiami projektu: dłuższy bok 800–1600 px i maksymalnie 300000 bajtów. Import przesyła galerie przez Webservice API i zapisuje mapowania pozwalające rozpoznawać wcześniej wysłane pliki.

## Konsekwencje

- Repozytorium pozostaje mniejsze, a cache i archiwum ograniczają ponowne żądania do źródła.
- Pierwsze przygotowanie zdjęć wymaga dostępu do lokalnych plików lub sieci; samo sklonowanie repozytorium nie dostarcza pełnego zbioru.
- Aktualizacja opublikowanego zestawu wymaga przygotowania wydania i zmiany przypiętej sumy kontrolnej.
- Walidacja ZIP, limity rozmiarów i normalizacja zwiększają złożoność importera, ale pozwalają odrzucać uszkodzone lub niepoprawne pliki.
- Cache nie zastępuje kopii galerii działającego sklepu. Brak zdjęcia w źródle musi być jawnie raportowany, a nie ukrywany jako udane pobranie.

[Import zdjęć](../IMAGE_IMPORT.md) · [Dane katalogu](../DATA.md) · [Lista ADR](README.md)
