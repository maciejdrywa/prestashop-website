# Weryfikacja odtwarzania sklepu z kopii zapasowej

Raport wygenerowany przez `deploy/verify-restore.sh`. Instrukcję tworzenia i przywracania kopii opisuje [README](../README.md#kopie-zapasowe-i-odtwarzanie).

## Wynik: ✅ POZYTYWNY

| Parametr | Wartość |
|---|---|
| Data | 2026-11-20 22:05:28 +0100 |
| Wersja repozytorium | `D4.4` @ `8a98348` |
| Archiwum | `backup_20261120_220210.tar.gz`, 125 MiB |
| SHA-256 archiwum | `0b2e01f1a3f36f28457d0defbf2e7a91ccadb68a24b96b7aaeb644c83bd09dc2` |
| Źródło danych do porównania | działający sklep, odczyt zaraz po kopii |
| Środowisko docelowe | projekt Compose `prestashop-restore-verify`: nowe wolumeny, sieć, hasła, certyfikat i konfiguracja nginx, `https://localhost:19443`; wspólne ze źródłem są tylko obraz `prestashop-website:8.2.8` i katalog `scraper-results` (wymagany przez usługę `certificates`) |
| Host | WSL (Linux), Docker 29.3.1 |
| **Czas odtwarzania** | **2 min 35 s** (limit 10 min 00 s) |
| Czas od zera (przygotowanie obrazów i środowiska + odtworzenie) | 2 min 41 s |

## Czas etapów

| Etap | Czas |
|---|---|
| Kopia zapasowa (backup.sh) | 0 min 17 s |
| Przygotowanie czystego środowiska (obrazy, .env, nginx) | 0 min 06 s |
| Odtworzenie sklepu (restore.sh, od pustego środowiska do HTTP 200) | 2 min 35 s |
| Porównanie danych, zdjęć i konfiguracji | 0 min 06 s |
| Kontrola funkcjonalności | 0 min 06 s |
| Cała weryfikacja | 3 min 20 s |

Czas odtwarzania mierzony jest od uruchomienia `restore.sh` na pustym środowisku (bez kontenerów i wolumenów) do odpowiedzi HTTP 200 sklepu. Obejmuje start usług, instalację pustego PrestaShop, walidację archiwum, import bazy, zdjęć i konfiguracji. Nie obejmuje budowy lub pobrania obrazów Docker, które należą do przygotowania środowiska; łączny czas od zera podaje tabela powyżej.

## Kontrole

| Wynik | Kontrola | Szczegóły |
|---|---|---|
| ✅ PASS | Odtworzenie skryptem restore.sh | kod 0, 2 min 35 s |
| ✅ PASS | Izolacja od sklepu źródłowego | kontenery źródła (4) te same i działające przez całe odtwarzanie |
| ✅ PASS | Czas odtwarzania | 2 min 35 s (limit 10 min 00 s) |
| ✅ PASS | Kompletność kategorii, produktów, wariantów i promocji | 19 liczników zgodnych ze źródłem |
| ✅ PASS | Zdjęcia produktów (img/p) | 11376 plików, identyczne ścieżki i zawartość (MD5) jak w źródle |
| ✅ PASS | Zawartość bazy (CHECKSUM TABLE) | 270/278 tabel identycznych, tabele danych sklepu bez różnic |
| ✅ PASS | Konfiguracja (ps_configuration) | wszystkie wartości zgodne poza domeną (PS_SHOP_DOMAIN*) i włączeniem sklepu |
| ✅ PASS | Eksport konfiguracji (export-config.php) | identyczny w źródle i po odtworzeniu (motyw, moduły, przewoźnicy, płatności, podatki, treści) |
| ✅ PASS | Dopasowanie do środowiska | domena localhost:19443/, sklep włączony |
| ✅ PASS | Strona główna | HTTP 200 https://localhost:19443/, zawiera nazwę sklepu "Twoje Drzwi do Sukcesu" |
| ✅ PASS | Strona kategorii | HTTP 200 https://localhost:19443/13-klamki-do-drzwi "Klamki do drzwi", lista produktów widoczna |
| ✅ PASS | Produkt z wariantami | HTTP 200 https://localhost:19443/zamki-nawierzchniowe/20-40-zamek-do-drzwi-gama-z-wkladka-gerda.html "Zamek do drzwi GAMA z wkładką GERDA" (id 20), nazwa i selektor wariantów widoczne |
| ✅ PASS | Zdjęcie produktu (plik) | HTTP 200 https://localhost:19443/img/p/2/6/26.jpg, image/jpeg, 23988 B |
| ✅ PASS | Zdjęcie produktu (miniatura, przyjazny URL) | HTTP 200 https://localhost:19443/26-home_default/zamek-do-drzwi-gama-z-wkladka-gerda.jpg, image/jpeg |
| ✅ PASS | Promocja na produkcie | HTTP 200 https://localhost:19443/zamki-nawierzchniowe/20-zamek-do-drzwi-gama-z-wkladka-gerda.html "Zamek do drzwi GAMA z wkładką GERDA", cena regularna i promocyjna widoczne |
| ℹ️ INFO | Reguły koszyka | aktywne: 0 |
| ✅ PASS | Wyszukiwarka | HTTP 200 https://localhost:19443/szukaj?s=Zamek fraza "Zamek", są wyniki, indeks wyszukiwania: 28517 wpisów |
| ✅ PASS | Dodanie do koszyka | HTTP 200 https://localhost:19443/index.php?controller=cart, produktów w koszyku: 1 |
| ✅ PASS | Podgląd koszyka | HTTP 200 https://localhost:19443/index.php?controller=cart&action=show, pozycja w koszyku widoczna |
| ✅ PASS | Rozpoczęcie zamówienia | HTTP 200 https://localhost:19443/zamowienie, formularz danych klienta widoczny |
| ✅ PASS | Logowanie do panelu | HTTP 200 https://localhost:19443/admin-dev/index.php?controller=AdminLogin, formularz logowania widoczny |
| ✅ PASS | Widoki bazy | ps_omnibus_price_history: 1924 wierszy |
| ✅ PASS | Webservice API | HTTP 200 /api/categories, klucz z .env działa |

## Kompletność danych

| Obiekt | Źródło | Po odtworzeniu | Zgodność |
|---|---:|---:|---|
| Kategorie | 89 | 89 | ✅ |
| Kategorie aktywne | 89 | 89 | ✅ |
| Produkty | 574 | 574 | ✅ |
| Produkty aktywne | 574 | 574 | ✅ |
| Powiązania produkt–kategoria | 1547 | 1547 | ✅ |
| Kombinacje (warianty) | 1269 | 1269 | ✅ |
| Atrybuty wariantów | 227 | 227 | ✅ |
| Cechy produktów | 0 | 0 | ✅ |
| Zdjęcia w bazie | 1388 | 1388 | ✅ |
| Ceny promocyjne (specific_price) | 25 | 25 | ✅ |
| Reguły cen katalogowych | 0 | 0 | ✅ |
| Reguły koszyka | 0 | 0 | ✅ |
| Stany magazynowe (suma) | 3803 | 3803 | ✅ |
| Przewoźnicy aktywni | 4 | 4 | ✅ |
| Moduły aktywne | 65 | 65 | ✅ |
| Strony CMS | 5 | 5 | ✅ |
| Widoki bazy | 1 | 1 | ✅ |
| Klienci | 23 | 23 | ✅ |
| Zamówienia | 24 | 24 | ✅ |

Zdjęcia produktów w `img/p`: 11376 plików (manifest archiwum: 11376).

## Zawartość bazy

- Tabel w bazie źródłowej: 278, identycznych po odtworzeniu (CHECKSUM TABLE): 270.
- Różnice oczekiwane (domena i odnośniki w treściach, klucz API, statystyki ruchu): cms_lang configuration configuration_lang connections connections_source guest shop_url webservice_account.
- Różnice w pozostałych tabelach (do oceny): brak.
- Tabele zmienione w sklepie źródłowym w trakcie kopii (nieporównywalne): brak.
- Różnice w tabelach danych sklepu: brak. Brakujące tabele: brak.

## Konfiguracja (wybrane ustawienia)

| Klucz | Źródło | Po odtworzeniu | Zgodność |
|---|---|---|---|
| `PS_COUNTRY_DEFAULT` | 14 | 14 | ✅ |
| `PS_CURRENCY_DEFAULT` | 1 | 1 | ✅ |
| `PS_GUEST_CHECKOUT_ENABLED` | 1 | 1 | ✅ |
| `PS_INVOICE` | 1 | 1 | ✅ |
| `PS_INVOICE_PREFIX` | #IN | #IN | ✅ |
| `PS_LANG_DEFAULT` | 1 | 1 | ✅ |
| `PS_MAIL_METHOD` | 2 | 2 | ✅ |
| `PS_ORDER_OUT_OF_STOCK` | 0 | 0 | ✅ |
| `PS_REWRITING_SETTINGS` | 1 | 1 | ✅ |
| `PS_SHOP_NAME` | Twoje Drzwi do Sukcesu | Twoje Drzwi do Sukcesu | ✅ |
| `PS_SSL_ENABLED` | 1 | 1 | ✅ |
| `PS_SSL_ENABLED_EVERYWHERE` | 1 | 1 | ✅ |
| `PS_STOCK_MANAGEMENT` | 1 | 1 | ✅ |
| `PS_TAX` | 1 | 1 | ✅ |
| `PS_TAX_DISPLAY` | 0 | 0 | ✅ |
| `PS_TIMEZONE` | Europe/Paris | Europe/Paris | ✅ |
| `PS_WEBSERVICE` | 1 | 1 | ✅ |

## Powtórzenie weryfikacji

```sh
bash deploy/verify-restore.sh --recreate
bash deploy/verify-restore.sh --cleanup   # usunięcie środowiska weryfikacyjnego
```
