# Audyt uporządkowania projektu

## Punkt wyjścia — 2026-11-16

Projekt korzysta z PrestaShop 8.2.8, PHP 8.1, MariaDB 10.11.14, Nginx i Mailpit. Katalog zawiera 574 produkty, 80 kategorii, 1269 kombinacji i 1388 zdjęć. Pięć typów miniatur daje 6940 plików miniatur. Autorski wygląd dotyczy modułu cookies. Śledzone repozytorium zawiera kod modułu i konfigurację obrazu.

Inicjalizator wykonywał 17 etapów, a obraz dodatkowo uruchamiał dziewięć skryptów konfiguracji przy każdym starcie. Powodowało to ponawianie zapisów i utrudniało zachowanie zmian administratora. README inicjalizacji liczył około 760 wierszy. Dokumentacja wdrożenia zawierała znaczniki konfliktu, a opisy projektu zapowiadały nieistniejący projekt testów E2E.

Przed zmianami przeszły 52 testy unittest Pythona, sześć dodatkowych funkcji parserów i pięć testów JavaScript. Kontrole źródła, planu masy, bazy oraz galerii zakończyły się poprawnie. HTTPS odpowiadał 200, HTTP przekierowywał do HTTPS, panel kierował do logowania, a Mailpit był dostępny.

Porównanie z początkowym planem ujawniło masę 61 kg produktu źródłowego 583 wobec 0,15 kg w JSON oraz stan produktu 466 zmniejszony z 3 do 2 sztuk przy zapisanym zamówieniu. Takie edycje i skutki sprzedaży są zachowywane przez wznowienie.

## Usunięcia i przeniesienie odpowiedzialności

Usunięto pięć scenariuszy frontendowych HTTP dotyczących CMS, cookies, menu, tekstów modułów i płatności. Usunięto pełny scenariusz zamówień z pobieraniem faktur oraz zależne od jego danych narzędzie kontroli PDF. Zachowano testy jednostkowe i integracyjne klas PHP oraz modułu cookies.

Usunięte pliki testowe obejmują `content-http-test.php`, `cookies-http-test.php`, `menu-http-test.php`, `module-texts-http-test.php`, `payments-http-test.php`, `orders-invoices-test.php` i `invoice-pdf-test.py` z `deploy/init/tests/`.

Usunięto dziewięć plików `shop/config/configure-*.sh`. Ich wywołania przeniesiono do wspólnego procesu inicjalizacji. Usunięto nieaktualny `scraper/plan.json`, którego stary kontrakt przestał odpowiadać eksporterowi. Zachowano wyniki scrapowania, lokalne galerie, próbki, raporty, kopie oraz narzędzia odświeżania opisów i okładek.

Aktualne instrukcje ze scenariusza faktur przeniesiono do konfiguracji i testów. Osobne dokumenty `order-invoice-scenario.md` i `sales-configuration-audit.md` zastąpiono uporządkowaną dokumentacją. Ich istotne ustalenia historyczne zapisano poniżej. Dziennik AI pozostaje zachowany.

## Zachowana historia konfiguracji sprzedaży

Audyt z 2026-11-15 wykazał aktywny przelew, pobranie i czek, puste dane przelewu oraz metody instalatora. Brakowało progu darmowej dostawy i limitu 50 kg. Faktury były włączone, ale brakowało danych wystawcy. Początkowe statusy oczekiwania miały ID 10 i 13, a płatności zaakceptowanej ID 2. Kod odczytuje ich identyfikatory z konfiguracji.

Kolejne prace wyłączyły czek, uzupełniły demonstracyjne instrukcje przelewu i uprawnienia PL/PLN. Utworzono InPost oraz DPD z opłatami 15 i 20 zł brutto, skonfigurowano próg 2000,01 zł i kurierską granicę 50 kg. Przywrócono odbiór osobisty oraz podstawową dostawę kurierską. Metody ekonomiczna i lekkich przesyłek pozostają wyłączone. Poprzednie testy klas koszyka potwierdziły ceny, rabaty, masę i uprawnienia płatności.

Weryfikacja z 2026-11-16 utworzyła wcześniej zamówienia 14 i 15, sprawdziła pobranie faktur po potwierdzeniu płatności oraz cztery polskie wiadomości w Mailpit. Sprawdzono tekst i wygląd PDF. Historia finansowa, testowe konta i dokumenty pozostały w lokalnej bazie. Był to wynik wcześniejszego etapu. Scenariusze HTTP usunięto z bieżącego zakresu testów zgodnie z zaakceptowanym planem.

## Nowy proces

Kontenerowy bootstrap przygotowuje certyfikat, sprawdza dane i gotowość usług oraz wywołuje `init-shop.php --resume`. Dziennik zawiera identyfikację instalacji, odciski wejścia i mapowania rekordów. Kontrole potwierdzają bazę i pliki. Początkowe stany są oddzielone od dalszych zmian magazynu.

Testy świeżej instalacji ujawniły konieczność uruchamiania kontroli gotowości jako `www-data`, przygotowania cache HTMLPurifier, uruchomienia kontenera Symfony przy pierwszej instalacji modułu cookies i odświeżenia cache procesu nadrzędnego po konfiguracji wykonanej w podprocesie. Poprawki należą do wspólnego procesu startowego.

Wydzielono raport katalogu, uzupełnianie metadanych i pobieranie pojedynczego obrazu. Klasy crawlera i kategorii otrzymały nazwy zgodne z konwencją Pythona oraz aliasy zgodności. Wspólny zapis ustawień przeniesiono do biblioteki integracji ze sklepem. Dodano polskie opisy funkcji i klas, nazwane stałe limitów oraz ustawienia UTF-8 i LF.

## Weryfikacja po zmianach

| Kontrola | Wynik |
|---|---|
| Python unittest | 58 testów przeszło, w tym sześć dotychczasowych funkcji parserów dołączonych do standardowego odkrywania |
| JavaScript cookies | 5 testów przeszło |
| PHP | Wszystkie 14 zachowanych skryptów testowych przeszło na osobnych wolumenach |
| Składnia | PHP, Python, JavaScript, PowerShell i Bash poprawne |
| Konfiguracja | Compose oraz `nginx -t` poprawne |
| Produkty i kombinacje | 574 produkty aktywne i 1269 kombinacji, ceny i masa zgodne z planem |
| Galerie | 1388 zdjęć i 6940 miniatur, wyjątek źródłowy 539 |
| Magazyn początkowy | Zakres 0–10, 57 produktów bez stanu, 9,93% |
| Pełny start na pustych wolumenach | 311,0 s, około 5 min 11 s, obrazy bazowe dostępne lokalnie i zdjęcia przygotowane na dysku |
| Powtórzenie tej samej komendy | 62,0 s, około 1 min 2 s, porównanie pełnych rekordów 16 tabel potwierdziło zachowanie danych i ID |
| Przerwanie | Zatrzymanie kontenera podczas importu produktów, ponowienie ukończyło pozostałe rekordy i etapy |
| Przyjęcie istniejącego sklepu | Archiwizacja dziennika w środowisku testowym i ponowne rozpoznanie zachowały dane |
| Ręczne zmiany | Zmienione cena, nazwa i masa produktu, ilość wariantu oraz treść CMS zachowane po przyjęciu i ponownym starcie |
| Błąd gotowości | Zatrzymany testowy Nginx i limit 1 s zakończyły bootstrap kodem 1 z diagnostyką |
| Dostępność | Strona główna, kategoria, produkt i zdjęcie HTTP 200, widoczny komunikat cookies, HTTPS z weryfikacją certyfikatu |
| Usługi | HTTP przekierowuje do HTTPS, panel odpowiada, Mailpit dostępny |
| Dokumentacja | Lokalne odnośniki poprawne, polecenia startowe i testowe sprawdzone w dostępnym środowisku |

Pomiary obejmują pełne polecenie Compose z `--build`. Czas samej pracy bootstrapu wyniósł odpowiednio 285,1 s i 46,1 s. Pomiar pobierania obrazów z pustą pamięcią podręczną pozostaje do osobnego wykonania.

Wykorzystano projekty `shop-cleanup-qa`, `shop-cleanup-fresh` i `shop-cleanup-final`, z odrębnymi wolumenami, bez publikowania portów testowych na hoście. Główna instalacja zachowała wagę 61 kg produktu 583, stan 2 produktu 466 i 16 zapisanych zamówień. Wyniki scrapowania, zdjęcia i dziennik AI pozostały zachowane.

Po zakończeniu kontroli usunięto kontenery i wolumeny trzech tymczasowych projektów testowych. Główne usługi nadal działają na dotychczasowych wolumenach.

## Ograniczenia i wcześniejsze problemy

- Produkt 539 ma pustą galerię źródłową. Wymaga jawnego wyjątku danych przy odbiorze.
- Obecny paker ZIP wymaga ręcznej kontroli limitu 1 GB. Uporządkowanie kodu zachowuje jego dotychczasowe działanie.
- Inicjalizacja wymaga lokalnego `images/`. Pobieranie paczki Release i zastępcze pobieranie URL stanowią odrębny zakres funkcjonalny.
- Narzędzie zdjęć zachowuje własny profil i dotychczasową konfigurację cookies, odrębną od głównego crawlera.
- Kontrole początkowego katalogu mogą zgłaszać celowe edycje i sprzedaż. Wznowienie sprawdza spójność aktualnego sklepu.
- Weryfikacja obejmuje Windows z Dockerem Linux amd64. Natywny Linux, macOS i emulacja Apple Silicon wymagają oddzielnego potwierdzenia.
- Repozytorium zachowuje dotychczasowy zakres testów jednostkowych i integracyjnych. Konfiguracja CI/CD i projekt E2E pozostają poza tym etapem.
