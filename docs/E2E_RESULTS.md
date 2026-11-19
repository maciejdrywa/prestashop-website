# Wyniki testu E2E

Szablon wyników kontroli odbiorowej scenariusza zakupu z `tests/e2e/test_checkout.py`. Opis testu i raportów: [instrukcja testów](../tests/README.md).

## Środowisko

| Pole | Wartość |
|---|---|
| Adres sklepu | — |
| Data | — |
| Gałąź / commit | — |
| Wykonał | — |

## Komenda

```sh
BASE_URL=https://adres python -m pytest -c pytest-e2e.ini --count=3
```

```powershell
$env:BASE_URL="https://adres"; python -m pytest -c pytest-e2e.ini --count=3
```

Przebiegi wykonuje się kolejno, bez resetu bazy. Na klastrze trzeba też ustawić `ADMIN_EMAIL` i `ADMIN_PASSWORD`.

## Przebiegi

| Nr | Czas [s] | Wynik | Referencja zamówienia | Numer faktury |
|---|---|---|---|---|
| 1 | — | — | — | — |
| 2 | — | — | — | — |
| 3 | — | — | — | — |

## Kryteria

- Każdy przebieg trwa najwyżej 300 s.
- Wszystkie trzy przebiegi kończą się sukcesem (3/3).

## Skąd brać dane

Dane są w `tests/test-results/python/junit.xml`, osobno dla każdego elementu `testcase`:

- Czas: wiersz `DURATION: x s (limit y s)` w `system-out`. Atrybut `time` elementu `testcase` podaje czas całego testu razem z przygotowaniem przeglądarki.
- Wynik: brak elementów `failure` i `error` w `testcase`.
- Referencja zamówienia: wiersz `Order <referencja>, ID <id>` w `system-out`.
- Numer faktury: wiersz `Invoice <plik>` w `system-out`. Nazwa pliku zawiera numer faktury.

Kolejne uruchomienie zastępuje `junit.xml`, dlatego wyniki trzeba przepisać przed następnym wywołaniem.

## Poprzednie pomiary

Pomiar z etapu W4.4, zapisany w [dzienniku AI](AI_LOG.md). Lokalny sklep, trzy kolejne przebiegi `--count=3` bez resetu bazy. Wszystkie przeszły.

| Nr | Czas [s] | Wynik | Referencja zamówienia | Numer faktury |
|---|---|---|---|---|
| 1 | 233,7 | sukces | RGRQDMIJN | FVDEMO000007 |
| 2 | 211,0 | sukces | VMJSDPGCZ | FVDEMO000008 |
| 3 | 237,2 | sukces | ZKDZVQHNR | FVDEMO000009 |

Zamówienia miały ID 19–21 i zmieniły status na „Płatność zaakceptowana”. Faktury miały ok. 468 KB, 1 stronę i referencję zamówienia. Dziennik podaje faktury jako zakres FVDEMO000007–000009; przypisanie do przebiegów według kolejności jest założeniem. Pomiar wykonano przed dodaniem wyboru DPD, blokady analityki i limitu `E2E_MAX_SECONDS`.
