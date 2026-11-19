# Audyt D1.4

Weryfikację wykonano 19 listopada 2026 na branchu `feat/shop-responsiveness`, opartym na `d5a6d2e` z aktualnego `main`.

## Lighthouse

Lighthouse 13.6.0, Chromium 156, profil desktopowy (`--preset=desktop`) i domyślne ustawienia ograniczenia CPU oraz sieci tego profilu. Pomiar wykonano na lokalnym HTTPS. Raport obejmuje dostępność i wydajność.

| Widok | Accessibility | Performance |
|---|---:|---:|
| Strona główna | 93 | 93 |
| Kategoria „Klamki do drzwi” | 90 | 98 |
| Produkt „Zatrzask balkonowy pochwyt palacza – kolory” | 94 | 99 |
| Logowanie | 93 | 98 |
| Rejestracja | 93 | 100 |

Wszystkie zmierzone strony przekroczyły wymagane Accessibility 85. [Raport strony głównej HTML](lighthouse.html) zawiera pełny wynik Lighthouse. [Wyniki JSON](results.json) zawierają wersję, czas, adresy, profil i rezultaty poszczególnych kontroli wszystkich pięciu stron.

Na stronie głównej zmierzono LCP 1689 ms, TBT 9.5 ms i CLS 0.0122. Wyniki dotyczą bieżącego stanu sklepu w profilu desktopowym. Brakuje pomiaru bazowego desktop sprzed zmian D1.4, dlatego te dane nie służą porównaniu wydajności przed zmianami i po nich.

Raport nadal wskazuje między innymi kontrast danych kontaktowych w stopce i rozróżnienie linku e-mail. Szczegółowe zgłoszenia są zapisane w HTML oraz JSON.

Do odtworzenia pomiaru uruchom poniższe polecenie z głównego katalogu projektu, z zainstalowanym Chromium. Zmienna `CHROME_PATH` wskazuje plik wykonywalny tej przeglądarki. Raport zapisze się w `docs/audits/lighthouse.html`.

```powershell
npx --yes --package lighthouse@13.6.0 lighthouse https://localhost --preset=desktop --chrome-flags="--headless --ignore-certificate-errors" --only-categories=accessibility,performance --output=html --output-path=docs/audits/lighthouse.html
```

## Zakres kontroli

- Sprawdzono stronę główną, kategorię, produkt, logowanie, rejestrację i pusty koszyk przy 360, 768 i 1280 px.
- Sprawdzono rozwijanie dwóch poziomów menu, Escape z powrotem fokusu, zmianę szerokości i wyszukiwanie na telefonie.
- Sprawdzono zakładki produktu klawiszami strzałek i Home, aktywowanie miniatur klawiaturą oraz nazwy przycisków ilości po zmianie wariantu.
- Sprawdzono mobilny koszyk z produktem i wszystkie kroki checkoutu. axe-core dla WCAG 2 A/AA oraz WCAG 2.1 A/AA zgłosił zero naruszeń, a szerokość dokumentu nie przekraczała viewportu. Wyniki znajdują się w `axeCheckout` w JSON.
- Sprawdzono błąd rejestracji po podaniu nieprawidłowego imienia. Pole otrzymało `aria-invalid` oraz `aria-describedby` wskazujące widoczny komunikat błędu.
- Sprawdzono otwarte mobilne menu w axe-core, obsługę stopki klawiaturą oraz opis zastępczej grafiki produktu bez galerii.

Testy zakupowe działają na osobnej instancji `prestashop-d1-4-qa`, z własną bazą i plikami. Odtworzenie tej instancji przez bootstrap na nowych wolumenach zajęło 288,7 s. Podczas końcowej sesji przed każdym testem sprawdzano, czy połączenie przeglądarki trafia na port QA 18443.

Przeszło 7 testów E2E w 256,84 s, w tym dwa pełne zakupy, przy 360 i 1280 px. Powstały zamówienia QA `SKZHMRIXG` i `WPTXDEEOC`. Po ostatniej korekcie ARIA ponownie przeszło 5 testów responsywności w 37,92 s. Przeszły również 22 kontrole jednostkowe checkoutu, Ruff dla zmienionego kodu Python i kontrola składni JavaScript.

Powyższe wyniki opisują weryfikację wykonaną podczas prac nad D1.4. Na polecenie użytkownika wycofano dodane testy responsywności, parametryzację zakupu dla urządzeń mobilnych, zmiany pomocniczych kroków E2E i ich opis w dokumentacji testów. Bieżący branch zachowuje kod testów W4 ze swojego punktu wyjścia.

Testy wykryły między innymi wyjście przycisku listy życzeń poza kolumnę produktu przy 768 px. Zawijanie kontrolek zakupu usunęło przyczynę przepełnienia.

Pierwsza konfiguracja przekierowania pytest została przesłonięta przez standardową konfigurację wtyczki. W tym przebiegu powstało testowe zamówienie `EMBLIDWAJ` na głównym lokalnym sklepie. Kolejne uruchomienie zatrzymano, a testy kontynuowano po wprowadzeniu jawnej kontroli portu QA.

Lighthouse i axe-core są kontrolami automatycznymi. Sprawdzenie klawiatury i układu uzupełnia ich zakres. Audyt nie obejmuje pełnego testu z czytnikiem ekranu, fizycznego urządzenia iOS ani deklaracji zgodności całego sklepu z WCAG.
