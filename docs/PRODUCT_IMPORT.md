# D2.3 — produkty przez Webservice

Importer Python obsługuje produkty, kategorie produktów, opcjonalne cechy, atrybuty, kombinacje i magazyn. Używa istniejącego klienta API i macierzy uprawnień D2.1. Importer jest częścią wspólnej komendy [D2.5](CATALOG_IMPORT.md); dotychczasowa ścieżka bootstrapu PHP pozostaje dostępna.

## Uruchomienie

Wymagane: działający sklep PrestaShop 8.2.8, konfiguracja API w istniejącym `.env`, Python z `scraper/requirements.txt`, polski kraj domyślny, PLN, kg, aktywne podatki i magazyn. API nie tworzy podatków. Import kategorii D2.2 musi wcześniej zapisać mapę dla tej samej instalacji.

Z katalogu repozytorium, w aktywnym środowisku Pythona:

```sh
python scraper/import_categories.py
mkdir -p logs/product-import
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop \
  php /opt/shop/init/export-import-context.php > logs/product-import/context.json
python scraper/import_products.py --context-file logs/product-import/context.json --dry-run
```

Eksporter PHP tylko odczytuje tożsamość i ustawienia sklepu. Nie zawiera klucza API. Kontekst jest ważny przez pięć minut przy rozpoczynaniu importu; przed kolejnym uruchomieniem wyeksportuj go ponownie. Dzięki temu klucz importera nie potrzebuje dostępu do `configurations`.

Właściwy zapis:

```sh
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop \
  php /opt/shop/init/export-import-context.php > logs/product-import/context.json
python scraper/import_products.py --context-file logs/product-import/context.json
```

Nie uruchamiaj jednocześnie importera PHP i API. Podczas importu wstrzymaj edycję katalogu. Blokada procesu chroni przed równoległymi importerami Python tej samej instalacji.

Nowe produkty są **nieaktywne**. Można je sprawdzić w panelu **Katalog → Produkty**, po referencji `pandomu-ID`. Galerie importuje [D2.4](IMAGE_IMPORT.md), a aktywacja po odbiorze zdjęć należy do finalizacji D2.5. Istniejące produkty zachowują aktywność i ilości magazynowe.

## Opcje i wznowienie

| Opcja | Działanie |
|---|---|
| `--products-file` | Domyślnie `scraper-results/products.json` |
| `--mapping-file` | Mapa kategorii; domyślnie mapa D2.2 wybrana według adresu API |
| `--context-file` | Wymagany świeży eksport ustawień |
| `--env-file` | Opcjonalna konfiguracja połączenia; domyślnie istniejący `.env` |
| `--state-file`, `--report-file` | Własne ścieżki dziennika i raportu JSON |
| `--ids 5 539` | Wybór źródłowych ID, z planem magazynu obliczanym na całym wejściu |
| `--dry-run` | Walidacja wejścia, schematów, VAT i mapy bez zapisów do sklepu, stanu i raportu |
| `--reset-stock` | Jawne odtworzenie początkowych ilości, również na istniejących produktach |

Domyślny stan i raport znajdują się w `logs/product-import/<host>/`. Zachowuj dziennik. Ponowne wykonanie tej samej komendy kontynuuje import i synchronizuje zmienione pola źródłowe. Osobne tryby `--resume` i `--sync` wspólnego katalogu należą do D2.5.

Zamiar POST/PUT jest zapisywany atomowo przed żądaniem, wynik po potwierdzeniu odczytem. Po utracie odpowiedzi importer szuka referencji albo nazwy w odpowiedniej grupie. Jeśli wynik POST pozostaje niepewny i nie można znaleźć rekordu, blokuje zapis zamiast wysyłać następny POST. Wymaga to sprawdzenia API i dziennika przez operatora. Nie usuwaj stanu, aby obejść konflikt.

Referencje `pandomu-ID` oraz `pandomu-ID-combination-ID` pozwalają rozpoznać istniejący katalog PHP. Powielone referencje, inny sklep, zmiana zapisanego ID lub dodatkowe kombinacje nieobecne w źródle blokują produkt. Pola niezarządzane, galerie i tłumaczenia nieaktywnych języków są zachowywane. Importer nie usuwa produktów ani słowników.

## Reguły danych

- Ceny są liczone przez `Decimal`, netto do sześciu miejsc; brutto musi zgadzać się do grosza. Modyfikator wariantu jest różnicą względem ceny bazowej. Korekty zaokrągleń odpowiadają PHP.
- VAT wymaga aktywnej grupy przypisanej do sklepu i jednej ogólnej reguły dla Polski, bez ograniczeń stanem lub kodem pocztowym. Równoważne grupy rozstrzyga najniższe ID, tak jak importer PHP.
- Kategorie obejmują pełne przypisania oraz przodków; kategoria domyślna wynika z dotychczasowych reguł źródła.
- Wagi źródłowe mają oznaczenie `source`. Brakujące otrzymują deterministyczną wagę `demo` według reguł `weights.php`, z nazwą reguły w raporcie.
- Opcjonalne `features: [{"name": "Materiał", "value": "Stal"}]` pochodzi ze struktury strony produktu. Brak pola oznacza pustą listę. Cechy i atrybuty wariantów mają osobne słowniki.
- Początkowy stan wynosi 0–10 sztuk łącznie na produkt. Deterministyczny plan daje około 10% produktów ze stanem zero (dla 574: 57). Suma ilości kombinacji odpowiada ilości produktu. Reguła wymaga co najmniej pięciu poprawnych produktów w wejściu.
- Ukończone ustawienie magazynu nie jest powtarzane przy wznowieniu. Aktualizacja cen i opisów nie resetuje sprzedaży. Jawny reset ustawia ponownie ilości i wariant domyślny.

Błąd produktu jest raportowany i nie blokuje niezależnych produktów. Błędne zależności globalne zatrzymują import przed zapisem. Kod zakończenia jest niezerowy przy błędach. Raport obejmuje ID, pochodzenie wag, ilości, zmianę wariantu domyślnego, liczniki zasobów i czas.

## Odbiór na osobnej instancji

Wyniki z 19 listopada 2026 r., PrestaShop 8.2.8, projekt Compose `prestashop-d21-tests`, osobne wolumeny, porty 18080/18443:

- 574 produkty źródłowe, 1269 kombinacji, 4 grupy atrybutów i 202 wartości; istniejące rekordy demonstracyjne sklepu nie są wliczane.
- 98 wag demonstracyjnych, 8 korekt cen wariantów, 57 produktów ze stanem zero.
- Nowe produkty nieaktywne, bez importowania zdjęć. Zwykły sklep nie był aktualizowany.
- Kontrola natywnym `check-products.php --all --with-combinations` potwierdziła pola, tłumaczenia, kategorie, brutto i wszystkie kombinacje. Osobne porównanie tylko do odczytu potwierdziło magazyn względem `stockPlans()` PHP, zachowując nieaktywność produktów.
- Pierwszy pełny przebieg: około 617 s, w tym tworzenie 571 produktów po wcześniejszej próbce trzech produktów. Ostatnia pełna synchronizacja trwała 41,911 s: 574 produkty i 1269 kombinacji pominięto, bez tworzenia, aktualizacji, resetowania magazynu i zmiany wariantów domyślnych.

Szczegółowe komendy testów są w [instrukcji testów](TESTING.md). Dane wykonania pozostają lokalnie w ignorowanym `logs/d23-test/`. Pomiar nie stanowi benchmarku D2.5: nie obejmuje 1000 produktów ani zdjęć.

Weryfikacja kodu: Ruff bez błędów, 159 testów offline i 11 subtestów; 9 testów integracyjnych przez HTTP oraz te same 9 przez HTTPS z weryfikacją certyfikatu; PHP lint eksportera bez błędów. Kontrola obejmowała również publiczny CLI i brak plików stanu/raportu po `--dry-run`.

Ręczny odbiór panelu pozostaje do wykonania: produkt prosty `pandomu-539`, produkt wariantowy `pandomu-5`, produkty z wagą `demo` oraz zerowym stanem wskazane w raporcie. Sprawdź opisy, ceny i ilości kombinacji. Zdjęcia, aktywacja i prezentacja kompletnych galerii na stronie sklepu pozostają poza D2.3.

Wspólną komendę przygotowania, importu API i finalizacji opisuje [D2.5](CATALOG_IMPORT.md).

[Indeks dokumentacji](README.md) · [Główne README](../README.md)
