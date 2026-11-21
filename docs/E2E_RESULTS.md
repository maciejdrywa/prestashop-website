# Wyniki testu E2E

Wyniki kontroli odbiorowej scenariusza zakupu z `tests/e2e/test_checkout.py`. Opis testu i raportów: [instrukcja testów](TESTING.md).

## Środowisko

| Pole | Wartość |
|---|---|
| Adres sklepu | `https://localhost` (lokalny Docker) |
| Data | 2026-11-19, 17:21 |
| Gałąź / commit | `W4.5`, przed commitem zmian |
| Wykonał | Wiktor Adamczyk |

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
| 1 | 64,9 | sukces | TMJFDLTLV | FVDEMO000010 |
| 2 | 51,0 | sukces | MARLYFYFG | FVDEMO000011 |
| 3 | 54,8 | sukces | AEZLDBKFB | FVDEMO000012 |

Trzy kolejne przebiegi `--count=3` bez resetu bazy, łącznie 179,7 s razem z przygotowaniem przeglądarki. Etap dodawania 10 produktów trwał 27,8–34,9 s (wcześniej 171–176 s). Zamówienia mają ID 22–24, a faktury ok. 470 KB, 2 strony i referencję zamówienia. Oba kryteria są spełnione.

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
