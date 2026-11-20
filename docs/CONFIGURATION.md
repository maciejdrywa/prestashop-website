# Konfiguracja sklepu

## Interfejs, treści i poczta

Domyślne ustawienia instalacji to Polska, język polski, PLN i kg. Inicjalizacja ustawia nazwę „Twoje Drzwi do Sukcesu”, usuwa rozpoznane produkty demonstracyjne i wyłącza slajdy, baner oraz tekst demonstracyjny. Menu odwzorowuje sześć głównych gałęzi i ich podkategorie.

Strony „O sklepie”, „Regulamin”, „Polityka prywatności”, „Dostawa” i „Formy płatności” powstają z `deploy/init/content`. Stopka prowadzi do właściwych CMS. Bloki „Bezpieczeństwo”, „Dostawa” i „Zasady zakupów” są spolszczone. Moduł cookies udostępnia informację z odnośnikiem do polityki prywatności.

`configure-mail.php` zapisuje SMTP `mailpit:1025` i generuje pełny polski motyw poczty natywnym `prestashop:mail:generate`. Odpowiada to funkcji „Generuj e-maile” w panelu. Gotowe pliki HTML/TXT trafiają do `mails/pl` i katalogów modułów w wolumenie sklepu. Uwzględniane są istniejące nadpisania poczty motywu. Powitanie, oczekiwanie na przelew, potwierdzenie zamówienia i płatności korzystają z tych polskich szablonów.

## Płatności

Aktywne są `ps_wirepayment` i `ps_cashondelivery`. Czek jest wyłączony. Dane przelewu mają charakter demonstracyjny. Uprawnienia obejmują Polskę, PLN, standardowe grupy klientów i referencje przewoźników. Brak integracji z rzeczywistymi operatorami.

## Dostawa

InPost kosztuje 15 zł brutto, DPD 20 zł brutto, ze stawką VAT 23% i bez opłaty manipulacyjnej. Zachowane są „Dostawa kurierska” oraz darmowy „Odbiór osobisty” instalatora. Dostawa ekonomiczna i lekkich przesyłek są wyłączone. Referencje zapisane w konfiguracji pozwalają rozpoznawać kolejne wersje przewoźnika tworzone przez panel.

Darmowa wysyłka obowiązuje powyżej 2000 zł brutto po rabatach, bez kosztów dostawy. Natywny próg jest włącznie, dlatego dla PLN zapisano `PS_SHIPPING_FREE_PRICE=2000.01`. Przy 2000 zł dostawa pozostaje płatna. Darmowa dostawa według masy jest wyłączona.

Kurierzy obsługują zamówienia do 50 kg włącznie. Zakres masy ma wyłączną górną granicę `50.000001`, a maksymalna masa wynosi 50 kg. Powyżej limitu dostępny jest odbiór osobisty. Testy klas koszyka obejmują progi 1999,99 / 2000 / 2000,01 zł oraz 49,999 / 50 / 50,001 / 50,000001 kg, rabaty i sumowanie sztuk.

## Wagi i magazyn

Dodatnia waga źródłowa jest zachowana. Przy jej braku importer wyznacza demonstracyjną wagę na podstawie kategorii, deterministycznie i niezależnie od wariantów. Wpływ każdej kombinacji na wagę wynosi zero. Początkowy plan magazynu ustawia 0–10 sztuk na produkt i około 10% produktów bez stanu, obecnie 57 z 574. Ilości wariantów sumują się do ilości produktu.

## Status i demonstracyjna faktura VAT

| Zdarzenie | Status | Opłacone | Faktura |
|---|---|---|---|
| Zakup przelewem | Oczekiwanie na płatność przelewem | Nie | Oczekuje |
| Zakup za pobraniem | Oczekiwanie na płatność przy odbiorze | Nie | Oczekuje |
| Potwierdzenie otrzymania pieniędzy przez administratora | Płatność zaakceptowana | Tak | Generowana i dostępna |

Administrator symuluje otrzymanie pieniędzy w panelu „Zamówienia”, zmieniając status na „Płatność zaakceptowana”. Natywny `OrderHistory` rejestruje płatność, generuje fakturę i wysyła polski e-mail z PDF do Mailpit. Klient pobiera dokument z historii i szczegółów zamówienia.

Wystawca ma jawnie fikcyjne dane „Adres demonstracyjny 1”, „Sklep demonstracyjny”, „00-000 Miasto testowe”, Polska. Prefiks to `#FV/DEMO/`, a dokument zawiera informację o edukacyjnym charakterze. Konfiguracja zachowuje numery istniejących faktur i statusy zapisanych zamówień.

Ręcznie sprawdź początkowy status i oczekiwanie na fakturę, następnie zmień status w panelu, odśwież historię klienta i pobierz PDF. Sprawdź produkty, VAT, kwoty i wiadomość w Mailpit. Powtórz dla obu metod płatności.

## Odtwarzanie

Początkowe ustawienia wykonuje wspólna inicjalizacja. `--resume` rozpoznaje istniejące powiązania i zachowuje dalsze edycje. Wybrane ustawienia można odtworzyć skryptami `configure-*.php`. `audit-sales.php` odczytuje bieżącą konfigurację jako JSON i pokazuje obecność danych bankowych bez ujawniania ich zawartości.

## Eksport konfiguracji w repozytorium

Plik `deploy/config/shop-config.json` przechowuje ustawienia wymagane do działania sklepu. Ostatni etap inicjalizacji (`configure-from-export.php`) odtwarza je automatycznie, więc po starcie nie trzeba konfigurować ich ręcznie w panelu. Plik powstaje z działającego sklepu i jest wersjonowany jak kod.

| Sekcja | Zawartość | Klucz naturalny |
|---|---|---|
| `languages`, `currencies`, `countries` | Aktywność języków i walut, kurs i precyzja waluty, aktywne kraje i ich strefy | ISO, nazwa strefy |
| `customer_groups` | Wyświetlanie cen brutto lub netto i widoczność cen w grupach klientów | Rola grupy (`@visitor`, `@guest`, `@customer`) lub nazwa |
| `taxes` | Grupy reguł podatkowych z regułami i stawkami | Nazwa grupy, ISO kraju |
| `modules`, `theme` | Stan zainstalowanych modułów (włączony lub wyłączony), aktywny motyw | Nazwa modułu i motywu |
| `cms` | Strony CMS z treścią, meta danymi, kategorią i aktywnością | `link_rewrite` |
| `carriers` | Przewoźnicy: nazwa, aktywność, limity, strefy, grupy, grupa VAT, zakresy i ceny netto | Klucz `SHOP_INIT_CARRIER_*` |
| `payments` | Uprawnienia modułów płatności: kraje, waluty, grupy klientów, przewoźnicy | Nazwa modułu |
| `configuration` | Allowlista ustawień: dane wystawcy, lokalizacja, VAT, dostawa, faktury, przelew, SMTP bez logowania, katalog, wyszukiwanie, moduły motywu | Klucz `Configuration` |

Plik nie zawiera ID instalacji. Odwołania zapisuje kluczami naturalnymi, np. `PS_CARRIER_DEFAULT: "STANDARD"` czy `PS_CONDITIONS_CMS_ID: "regulamin"`. Odnośniki do stron w treściach CMS są zapisywane jako `{{cms:regulamin}}`, a adres sklepu jako `{{shop_url}}`. Podczas odtwarzania zamieniają się na adresy bieżącej instalacji. Eksport jest deterministyczny: ten sam stan daje identyczny plik, co ułatwia przegląd zmian w PR.

### Wykluczone dane

Eksport nie odczytuje tabel klientów, adresów, zamówień, koszyków, pracowników ani kluczy Webservice. Pomija klucze spoza allowlisty, w szczególności:

- hasła i loginy SMTP oraz klucze DKIM,
- sole i losowe wartości modułów,
- adresy e-mail sklepu i odbiorców powiadomień,
- domenę, SSL i Webservice (pochodzą z `.env` i `configure-webservice.php`),
- liczniki faktur,
- ID zależne od instalacji.

Przed zapisem i przed odtworzeniem każda nazwa pola i wartość, także po zdekodowaniu encji HTML, przechodzi kontrolę. Pliku nie da się zapisać ani zastosować, jeśli wykryje ona:

- nazwę pola wskazującą na hasło, klucz, token, sól lub adres,
- adres e-mail, numer telefonu (także 9 cyfr bez separatorów i format stacjonarny), IBAN lub długi ciąg cyfr,
- PESEL, NIP lub numer karty z poprawną sumą kontrolną,
- token dłuższy niż 31 znaków (poza nazwami plików w `/img/`) albo klucz API z typowym prefiksem lub JWT,
- przy eksporcie: dowolną wartość `_COOKIE_KEY_`, hasła bazy, `PRESTASHOP_API_KEY` albo hasła administratora.

Tę samą kontrolę bez Dockera wykonuje `tests/unit/test_config_export.py`.

### Eksport po zmianie w panelu

Po zmianie ustawień w panelu wyeksportuj konfigurację i dołącz diff pliku do PR. W PowerShell nie przekierowuj wyjścia operatorem `>`, bo zapisze plik w UTF-16. Użyj `--output` i `docker compose cp`:

```sh
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/export-config.php --output=/tmp/shop-config.json
docker compose --env-file .env -f deploy/compose.yaml cp shop:/tmp/shop-config.json deploy/config/shop-config.json
```

`export-config.php --check` porównuje sklep z plikiem w repozytorium bez zapisu. Kod 2 oznacza różnice i wypisuje ich listę, np. `carriers/STANDARD`. Obiekty dodane w sklepie po eksporcie (nowy przewoźnik, strona, moduł, język) są oznaczone jako `(tylko w sklepie)`.

Eksport odmawia zapisu stanu sprzecznego z warunkami etapów inicjalizacji, np. włączonego slidera demonstracyjnego albo pustych danych wystawcy. Taki plik zatrzymałby `--resume` u wszystkich członków zespołu.

### Odtwarzanie i inicjalizacja

`configure-from-export.php` porównuje sklep z plikiem i zapisuje wyłącznie różniące się elementy. Poza transakcją aktywuje motyw i instaluje brakujące moduły (operacje DDL). Włączanie modułów i pozostałe zapisy wykonuje w jednej transakcji. Przed jej zatwierdzeniem ponownie eksportuje sklep i wymaga pełnej zgodności z plikiem oraz spełnienia warunków wcześniejszych etapów inicjalizacji. Niezgodność wycofuje zapisy. Odcisk SHA-256 zastosowanego pliku zapisuje w `SHOP_INIT_CONFIG_EXPORT_SHA256`. Podgląd zmian bez zapisu:

```sh
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/configure-from-export.php --dry-run
```

W inicjalizacji jest to etap 23, po wszystkich konfiguratorach. Tryb pełny stosuje plik zawsze. `--resume`, czyli bootstrap, stosuje go tylko wtedy, gdy jego odcisk różni się od zapisanego. Dzięki temu nowy plik z repozytorium trafia do każdej instancji przy kolejnym starcie, a późniejsze edycje w panelu pozostają do czasu następnej zmiany pliku.

Elementy obecne w sklepie, a nieobecne w pliku, pozostają bez zmian, np. dodatkowa strona CMS albo moduł. Wyjątkiem jest lista aktywnych krajów, odtwarzana w całości. Języki dodane w sklepie po eksporcie nie są różnicą: teksty z pliku trafiają do języków z pliku, a puste pola nowych obiektów w pozostałych językach są uzupełniane tekstem języka domyślnego.

Języki i waluty z pliku muszą istnieć w instalacji, bo ich dodanie wymaga pakietów danych. Odtwarzanie przywraca usuniętego przewoźnika z tą samą referencją, więc powiązania produktów i płatności pozostają ważne. Tworzy też brakującą stronę CMS, grupę podatkową i podatek o danej stawce. Odnośniki do odtworzonej strony w blokach linków stopki, w menu i w treściach przenosi na jej nowe ID. Zmiana reguł grupy podatkowej użytej w zamówieniach tworzy jej nową wersję, jak w panelu, aby historia zamówień zachowała stawki.

**Zmiany w konfiguratorach:** wartości z pliku nadpisują to, co zapisały wcześniejsze konfiguratory. Po zmianie konfiguratora (np. ceny w `lib/carriers.php`) wyeksportuj plik ponownie w tym samym PR. Inaczej etap 23 przywróci poprzednią wartość. Dla taryf przewoźników zgodność pilnuje `tests/unit/test_config_export.py`.

**Zastosowanie całego pliku:** nowa wersja pliku jest stosowana w całości, a nie jako różnica względem poprzedniej. Lokalne edycje panelu w obszarach z pliku zostaną wtedy zastąpione wartościami z repozytorium.

**Poza zakresem eksportu:** pozycje menu (`configure-menu.php`), bloki linków i reassurance, kafle motywu, pozycje hooków, tryb serwisowy (`PS_SHOP_ENABLE`, ustawiany przez `backup.sh --maintenance`), przyjazne adresy URL (zmiana wymaga regeneracji `.htaccess` w panelu), domena, SSL i Webservice. Na świeżej instalacji odtwarzają je konfiguratory. Zmiany z panelu w tych obszarach trzeba przenieść do konfiguratorów.
