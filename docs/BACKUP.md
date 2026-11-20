# Kopie zapasowe i odtwarzanie

Polecenia wykonuj z katalogu głównego repozytorium w Bashu: na Linux/macOS w terminalu, na Windows w WSL lub Git Bash. Potrzebne są Docker z Compose v2 i lokalny `.env`. Ograniczenia Git Bash opisano poniżej.

Archiwa zawierają `.env` i klucze sklepu. Przechowuj je poza Gitem, z dostępem ograniczonym jak do haseł.

Przygotowanie środowiska opisuje [wdrożenie](DEPLOYMENT.md), a eksport samych ustawień [konfiguracja](CONFIGURATION.md).

## Kopia zapasowa sklepu (backup.sh)

Skrypt `deploy/backup.sh` umożliwia wykonanie spójnej, kompletnej i bezpiecznej kopii zapasowej działającego sklepu PrestaShop za pomocą jednej komendy.

```sh
# Standardowe wykonanie kopii zapasowej w tle (bez przestoju sklepu)
bash deploy/backup.sh

# Wykonanie z przełączeniem sklepu w tryb konserwacji na czas backupu
bash deploy/backup.sh --maintenance

# Zapis archiwum do wskazanego katalogu docelowego
bash deploy/backup.sh --output-dir /sciezka/do/kopii
```

### Zawartość archiwum (`backup_YYYYMMDD_HHMMSS.tar.gz`)

- `database.sql`: pełny, transakcyjny zrzut bazy MariaDB (`--single-transaction`, `--quick`, UTF-8).
- `images/p/`: kompletne drzewo katalogów ze zdjęciami produktów (oryginały i miniatury).
- `config/`: kluczowe pliki konfiguracyjne (`parameters.php` z kluczami cookie/szyfrowania, `.htaccess`, `.env` oraz opcjonalne `parameters.yml` i `defines_custom.inc.php`).
- `manifest.json`: metadane kopii (wersja PrestaShop, data ISO 8601, podsumowanie rozmiarów i sumy kontrolne SHA-256 dla każdego pliku).

### Bezpieczeństwo i uprawnienia

- **Poufność poświadczeń**: hasła bazy danych nie są przekazywane jako argumenty CLI na hoście ani wewnątrz kontenera (`ps aux`). Zmienna `MYSQL_PWD` jest przekazywana bezpośrednio do środowiska procesu `mariadb-dump` za pomocą `env`, eliminując poświadczenia z listy procesów (`/proc/$PID/cmdline`).
- **Kompatybilność z Windows / Git Bash**: skrypt definiuje `MSYS_NO_PATHCONV=1`, zapobiegając błędnej konwersji uniksowych ścieżek kontenerów (np. `/var/www/...`) na ścieżki Windows przez interpreter MSYS2 w środowisku Git Bash.
- **Restrykcyjne uprawnienia**: katalog docelowy `deploy/backups` tworzony jest z uprawnieniami `0700` (`drwx------`), a plik archiwum `0600` (`-rw-------`).
- **Ochrona przed wyciekiem do Git**: katalog `deploy/backups/` jest wykluczony w głównym `.gitignore` oraz zawiera lokalny plik `.gitignore` blokujący przypadkowe śledzenie archiwów.
- **Sprzątanie zasobów i diagnostyka awarii**: wszelkie operacje pośrednie realizowane są w katalogu tymczasowym `mktemp -d` usuwanym przez pułapkę sygnałów (`trap ... EXIT INT TERM`). W razie wystąpienia błędu (`set -e`) skrypt natychmiast rejestruje czytelny komunikat `[ERROR]` ze wskazaniem kodu wyjścia i usuwa pliki tymczasowe.
- **Gwarancja przywrócenia**: przy użyciu opcji `--maintenance` sklep jest automatycznie przywracany do stanu aktywnego nawet w przypadku przerwania działania skryptu błędem lub sygnałem SIGINT.

### Spójność transakcyjna bazy a synchronizacja zdjęć

Zrzut bazy MariaDB realizowany jest z flagami `--single-transaction` oraz `--quick`, co zapewnia pełną spójność transakcyjną (ACID, poziom izolacji `REPEATABLE READ`) dla silnika InnoDB w jednym punkcie czasu bez blokowania odczytów ze sklepu.

Zdjęcia produktów (`img/p`) są kopiowane z kontenera w osobnym kroku (`docker compose cp`). Jeśli w trakcie wykonywania kopii na sklepie odbywa się intensywny ruch (np. administrator dodaje, edytuje lub usuwa produkty w Back Office), stan plików graficznych na dysku może minimalnie rozbiec się ze zrzutem bazy SQL. Dodatkowo tryb konserwacji (`PS_SHOP_ENABLE=0`) wyłącza widok sklepu dla klientów, ale nie blokuje aktywności zalogowanych administratorów.

> **Rekomendacja:** W celu zagwarantowania 100% atomowej spójności całego środowiska (baza danych + pliki multimedialne) zaleca się wykonywanie kopii zapasowej z parametrem `--maintenance` poza godzinami szczytu lub w planowanym oknie serwisowym.

### Uprawnienia na partycjach NTFS / Windows

Restrykcyjne uprawnienia katalogu `0700` (`drwx------`) oraz pliku archiwum `0600` (`-rw-------`) opierają się na standardowym modelu uprawnień POSIX i działają w pełni na systemach Linux oraz macOS.

Na maszynach deweloperskich z systemem Windows korzystających z systemów plików NTFS / FAT32 (np. pod Git Bash lub WSL na ścieżkach `/mnt/c/...`), semantyka uprawnień POSIX może być emulowana lub ignorowana przez domyślne listy kontroli dostępu (ACL). W przypadku kopiowania lub przenoszenia archiwum na nośniki zewnętrzne lub do współdzielonych katalogów na platformie Windows zaleca się dodatkowe zabezpieczenie archiwum przed niepowołanym dostępem (np. szyfrowanie za pomocą GPG lub archiwizatora z szyfrowaniem AES-256).

## Odtwarzanie na nowym komputerze

**Na nowym komputerze:**

1. Sklonuj repozytorium i przygotuj `scraper-results/` (`products.json`, `categories.json`, komplet zdjęć w `images/` z GitHub Releases). Kopia ich nie zawiera, a usługa `certificates`, od której zależy start bazy, sprawdza ten katalog.
2. Skopiuj archiwum do `deploy/backups/`.
3. Utwórz `.env` z `.env.example` albo użyj `--use-backup-env` (tworzy `.env` z kopii). W obu przypadkach ustaw `TLS_CERT_DIR` na katalog certyfikatów tego komputera.
4. Uruchom `bash deploy/restore.sh --latest` (lub `--latest --use-backup-env`). Skrypt uruchomi kontenery, zainstaluje pusty PrestaShop i zastąpi go kopią. Nie uruchamiaj potem bootstrapu z importem.

## Odtwarzanie sklepu (restore.sh)

Skrypt `deploy/restore.sh` odtwarza sklep z archiwum utworzonego przez `backup.sh`. Przywraca bazę MariaDB, zdjęcia produktów (`img/p`) i pliki konfiguracyjne.

```sh
# Odtworzenie z najnowszego archiwum w deploy/backups
bash deploy/restore.sh --latest

# Odtworzenie ze wskazanego archiwum
bash deploy/restore.sh deploy/backups/backup_YYYYMMDD_HHMMSS.tar.gz

# Sama kontrola archiwum, bez Dockera i bez zmian w sklepie
bash deploy/restore.sh deploy/backups/backup_YYYYMMDD_HHMMSS.tar.gz --verify-only
```

Na Linuksie i macOS skrypt można też uruchomić bezpośrednio: `./deploy/restore.sh --latest`.

> **Odtwarzaj wyłącznie archiwa z zaufanego źródła.** Sumy SHA-256 z `manifest.json` wykrywają uszkodzenie archiwum, ale nie celową modyfikację, bo manifest jest częścią tego samego archiwum. Skrypt ogranicza skutki spreparowanej kopii:
> - zrzut importuje tymczasowe konto MariaDB z uprawnieniami wyłącznie do bazy tymczasowej, w trybie `--sandbox`; SQL ze zrzutu nie zmieni bazy produkcyjnej ani kont,
> - w `images/p` dopuszczone są tylko obrazy, pliki `fileType` i standardowy `index.php` PrestaShopa (sprawdzany po sumie SHA-256),
> - `parameters.php` jest sprawdzany tokenizerem PHP przed wczytaniem.
>
> Treść odtworzonej bazy (np. opisy produktów, konta) pochodzi jednak w całości z archiwum.

### Przebieg

1. **Walidacja archiwum, zanim cokolwiek się zmieni:**
   - poprawność gzip/tar i dokładnie jeden katalog `backup_*`,
   - brak ścieżek bezwzględnych, `..` i dowiązań,
   - obecność `database.sql`, `manifest.json`, `config/parameters.php` i `images/p/`,
   - zgodność listy plików z manifestem (brakujące i nadmiarowe) oraz sumy SHA-256 każdego pliku,
   - znacznik `Dump completed`, tabele `configuration`, `shop_url` i `product` oraz klucze `cookie_key`, `cookie_iv`, `secret`.
2. **Środowisko:**
   - gdy `db` lub `shop` nie działają, skrypt uruchamia `docker compose up -d`, potem czeka na gotowość bazy i koniec pracy instalatora PrestaShop,
   - wersja PrestaShop w kopii musi być taka sama jak w kontenerze.
3. **Ochrona danych:**
   - skrypt pokazuje stan bieżącego sklepu i prosi o wpisanie nazwy bazy; bez terminala wymaga `--yes`,
   - po potwierdzeniu robi kopię bezpieczeństwa bieżącego stanu przez `backup.sh` do `deploy/backups/pre-restore/`; `--latest` nie bierze kopii z tego katalogu,
   - blokada `deploy/backups/.restore.lock` uniemożliwia dwa odtwarzania naraz,
   - skrypt odmawia działania, gdy zostały dane po poprzednim odtwarzaniu (bazy `_restore_tmp`/`_pre_restore`, `img/p.pre-restore`, `var/restore-previous/`).
4. **Baza:**
   - nginx zostaje zatrzymany na czas odtwarzania,
   - zrzut trafia do bazy tymczasowej `<DB_NAME>_restore_tmp`; po sprawdzeniu liczby tabel jedna instrukcja `RENAME TABLE` podmienia tabele, a poprzednie przenosi do `<DB_NAME>_pre_restore`,
   - jeśli import się nie uda, bieżąca baza zostaje bez zmian,
   - widoki (np. `ps_omnibus_price_history` modułu `shopomnibus`) nie mogą być przenoszone przez `RENAME TABLE` między bazami, a ich `DEFINER` ze zrzutu wymagałby uprawnienia `SUPER`. Zrzut jest importowany z widokami `SQL SECURITY INVOKER` bez `DEFINER`. Po podmianie tabel konto importu tworzy widoki w bazie sklepu na nowo, a root ustawia jako `DEFINER` konto aplikacji (`DB_USER`). Zrzut z wyzwalaczami, procedurami lub zdarzeniami z `DEFINER` jest odrzucany. Rollback nie zmienia widoków: odwołują się do tabel po nazwie, więc działają z przywróconymi tabelami,
   - przerwanie (Ctrl+C, `kill`, zamknięcie okna terminala) na dowolnym etapie po imporcie kończy się rollbackiem; gdy przerwanie trafi w podmianę tabel, skrypt czeka, aż MariaDB zakończy `RENAME TABLE`, i dopiero wtedy ustala, czy podmiana nastąpiła.
5. **Zdjęcia i konfiguracja:**
   - `img/p` trafia najpierw do katalogu tymczasowego, a poprzednia wersja zostaje jako `img/p.pre-restore`,
   - z kopii pochodzą `cookie_key`, `cookie_iv`, `secret` i klucze API z `parameters.php`, więc hasła klientów i administratora z kopii działają,
   - dane połączenia z bazą pochodzą z bieżącego środowiska kontenera `shop`,
   - przywracane są też `parameters.yml`, `defines_custom.inc.php` i `.htaccess`,
   - poprzednie wersje tych plików trafiają do `var/restore-previous/` (właściciel root, uprawnienia `0600`).
6. **Dopasowanie do środowiska:**
   - domena z `PS_DOMAIN` jest wpisywana do `ps_shop_url` i `PS_SHOP_DOMAIN(_SSL)`,
   - gdy domena kopii jest inna niż bieżąca, bezwzględne odnośniki do starej domeny w treściach (`cms_lang.content`, `configuration_lang.value`, `psreassurance.link`) są przepisywane na bieżącą domenę; bez tego strony CMS i stopka po odtworzeniu na innej domenie prowadziłyby do starego sklepu,
   - klucz konta Webservice dostaje wartość `PRESTASHOP_API_KEY` z bieżącego `.env`,
   - motyw i moduły projektu są kopiowane z obrazu (`/opt/shop-themes`, `/opt/shop-modules`), bo kopia ich nie zawiera,
   - skrypt zawsze włącza sklep (`PS_SHOP_ENABLE=1`): kopia z `backup.sh --maintenance` ma ten parametr ustawiony na 0. Jeśli sklep był wyłączony celowo, wyłącz go ponownie w panelu,
   - na końcu skrypt czyści cache, generuje `.htaccess`, odświeża w nim nagłówek tożsamości instalacji dla importera API (blok `catalog-import-identity`, wymagany przez etap `configure-webservice.php`) i restartuje kontener `shop`.
7. **Kontrola:**
   - nginx jest uruchamiany (także gdy wcześniej nie działał),
   - liczba produktów i zdjęć musi zgadzać się z kopią,
   - `https://<PS_DOMAIN>/` musi zwrócić HTTP 200 w czasie `RESTORE_TIMEOUT`, najwyżej 300 s. Skrypt sprawdza to z hosta przez `curl`, a gdy `curl` nie ma lub nie może się połączyć, także z kontenera nginx: `https://127.0.0.1/` z nagłówkiem `Host: <PS_DOMAIN>`, bo nginx nasłuchuje tylko na IPv4,
   - błąd na dowolnym etapie po podmianie przywraca poprzednią bazę, zdjęcia i konfigurację.

### Opcje i zmienne środowiskowe

| Opcja | Znaczenie |
|---|---|
| `--latest` | Najnowsze `backup_*.tar.gz` z katalogu kopii (bez podkatalogu `pre-restore/`) |
| `--verify-only` | Tylko walidacja archiwum |
| `-y`, `--yes` | Bez interaktywnego potwierdzenia (także `RESTORE_ASSUME_YES=1`) |
| `--no-safety-backup` | Bez kopii bezpieczeństwa bieżącego stanu |
| `--use-backup-env` | Tworzy brakujący `.env` z kopii (uprawnienia `0600`) |
| `--keep-previous` | Po udanym odtworzeniu zachowuje `<DB_NAME>_pre_restore`, `img/p.pre-restore` i `var/restore-previous/` |
| `--ignore-version` | Dopuszcza różnicę wersji PrestaShop |

| Zmienna | Domyślnie |
|---|---|
| `ENV_FILE` | `<repo>/.env` |
| `COMPOSE_FILE_PATH` | `<repo>/deploy/compose.yaml` |
| `BACKUP_DIR` | `deploy/backups` (kopie bezpieczeństwa: `BACKUP_DIR/pre-restore`) |
| `RESTORE_TIMEOUT` | `900` sekund oczekiwania na bazę i sklep; kontrola HTTP najwyżej 300 s |
| `SHOP_URL` | `https://<PS_DOMAIN>/` |

- Bieżący `.env` nie jest nadpisywany. Gdy różni się od `.env` z kopii, skrypt wypisuje tylko nazwy różniących się zmiennych, bez wartości.
- Konto administratora i jego hasło pochodzą z kopii, a nie z `ADMIN_PASSWORD` w bieżącym `.env`.
- Kopia bezpieczeństwa wymaga domyślnych `ENV_FILE` i `COMPOSE_FILE_PATH`, bo `backup.sh` używa tych ścieżek.
- **Windows:** skrypt uruchamiaj w PowerShellu poleceniem `bash deploy/restore.sh …` (WSL) lub w Git Bash. W Git Bash ścieżki dla `docker` są zamieniane przez `cygpath -m`. W WSL `TLS_CERT_DIR=C:/…` z `.env` jest przekazywany jako `/mnt/c/…`. Skrypt nie przebudowuje istniejących kontenerów (`start`, `up --no-recreate`), ale polecenia `docker compose up` wykonuj w tym samym środowisku, w którym kontenery zostały utworzone (np. PowerShell), bo inna ścieżka projektu powoduje ich przebudowę.

### Odtworzenie na nowym komputerze

1. Sklonuj repozytorium, przygotuj `scraper-results/` (`products.json`, `categories.json`, `images/` z GitHub Releases; wymaga ich usługa `certificates`, od której zależy start bazy), utwórz `.env` (albo użyj `--use-backup-env` i popraw `TLS_CERT_DIR`) i przygotuj certyfikat (`deploy/generate-cert.sh` lub `.ps1`).
2. Skopiuj archiwum do `deploy/backups/`.
3. Uruchom `bash deploy/restore.sh --latest`. Skrypt uruchomi środowisko, poczeka na instalację pustego sklepu i zastąpi go kopią.

Kopia nie zawiera dziennika inicjalizacji `var/import/`. Po odtworzeniu nie uruchamiaj bootstrapu z importem (`run bootstrap`, `init-shop.php --resume`). Na nowym wolumenie dziennika nie ma wcale, a na istniejącym opisuje dane sprzed odtworzenia.

### Ręczne odzyskanie po przerwanym rollbacku

Gdy rollback sam się nie powiedzie (np. Docker przestał odpowiadać), skrypt wypisze, gdzie leżą poprzednie dane:

- **Baza:** poprzednie tabele są w `<DB_NAME>_pre_restore`. Najprościej przywrócić stan kopią bezpieczeństwa: `bash deploy/restore.sh deploy/backups/pre-restore/backup_….tar.gz`. Wcześniej usuń `<DB_NAME>_pre_restore`, gdy jego zawartość nie jest już potrzebna.
- **Zdjęcia:** w kontenerze `shop` wykonaj `cd /var/www/html/img && rm -rf p && mv p.pre-restore p`.
- **Konfiguracja:** poprzednie pliki są w `/var/www/html/var/restore-previous/`. Ścieżka pliku jest zapisana w nazwie, z `/` zamienionym na `_`.

### Ograniczenia

- Kopia obejmuje tylko `img/p`. Zdjęcia kategorii, producentów i logo (`img/c`, `img/m`, `img/*.jpg`) oraz katalogi `upload/` i `download/` nie są przywracane.
- Moduły zainstalowane ręcznie z panelu, spoza obrazu, nie są częścią kopii.
- Po udanym odtworzeniu z `--keep-previous` kolejne odtwarzanie wymaga usunięcia bazy `<DB_NAME>_pre_restore` oraz katalogów `img/p.pre-restore` i `var/restore-previous/` w kontenerze `shop`. Skrypt o tym przypomina.
- **Git Bash na Windows:** `backup.sh` zamienia ścieżki przekazywane do `docker` (`--env-file`, `-f`, cel `docker compose cp`) i do Pythona generującego manifest przez `cygpath -m`, tak jak `restore.sh`. Alias `python3` ze Sklepu Microsoft bez zainstalowanego Pythona jest wykrywany i zastępowany generatorem w Bashu. Ścieżka Git Bash nie była jeszcze sprawdzona w działaniu; przetestowana jest WSL.

## Weryfikacja odtwarzania (verify-restore.sh)

Skrypt `deploy/verify-restore.sh` sprawdza, że kopia działającego sklepu daje się odtworzyć na czystym środowisku w wymaganym czasie i że po odtworzeniu sklep ma te same dane i działa. Dane głównego sklepu są wyłącznie czytane. Ze sklepem źródłowym środowisko weryfikacyjne dzieli tylko obraz `prestashop-website:8.2.8` (budowany wyłącznie, gdy go brak, więc obraz źródła nie jest podmieniany) i katalog `scraper-results/`.

```sh
bash deploy/verify-restore.sh                                    # pełna weryfikacja
bash deploy/verify-restore.sh --recreate --down-after            # ponowienie i usunięcie środowiska po sukcesie
bash deploy/verify-restore.sh --archive deploy/backups/backup_….tar.gz   # gotowe archiwum, bez porównania ze sklepem
bash deploy/verify-restore.sh --cleanup                          # usunięcie środowiska weryfikacyjnego
```

### Przebieg

0. **Kontrola czystości** przed kopią: brak kontenerów i wolumenów projektu `prestashop-restore-verify` (inaczej `--recreate` albo `--cleanup`) i wolne porty 19080/19443/19025.
1. **Kopia zapasowa** działającego sklepu przez `backup.sh` do `deploy/backups/verify/archive/` (poprzednie archiwa weryfikacji są usuwane, bo zawierają `.env` i klucze).
2. **Odcisk sklepu źródłowego** przed kopią (`CHECKSUM TABLE`) i zaraz po niej. Tabele zmienione w trakcie kopii (np. nowe zamówienie) są oznaczane jako nieporównywalne. Odcisk po kopii: 18 liczników (kategorie, produkty, kombinacje, zdjęcia, ceny promocyjne, reguły koszyka, stany, przewoźnicy, moduły, klienci, zamówienia), `CHECKSUM TABLE` wszystkich tabel, suma MD5 plików `img/p`, wybrane i wszystkie wartości `ps_configuration`, eksport `export-config.php`.
3. **Czyste środowisko:** projekt Compose `prestashop-restore-verify` z nowymi wolumenami, siecią, losowymi hasłami bazy i administratora, własnym certyfikatem, kluczem API i konfiguracją nginx (`deploy/backups/verify/nginx.conf`: nagłówek `Host` z portem 19443, bez którego PrestaShop zapętliłby przekierowania), porty 19080/19443/19025. Zmienne powłoki, które Compose stawia ponad `--env-file` (`COMPOSE_PROJECT_NAME`, `DB_VOLUME_NAME`, porty itd.), są usuwane, żeby odtwarzanie nie trafiło w sklep źródłowy. Brakujące obrazy są budowane lub pobierane przed pomiarem.
4. **Odtworzenie** przez `restore.sh --yes --no-safety-backup` z pomiarem czasu. Po odtworzeniu skrypt sprawdza, że kontenery sklepu źródłowego są te same i działają. Pomiar obejmuje start usług, instalację pustego PrestaShop, walidację archiwum, import bazy, zdjęć i konfiguracji oraz kontrolę HTTP 200. Limit: `RESTORE_TIME_LIMIT` (domyślnie 600 s).
5. **Porównanie** odtworzonego sklepu ze źródłem (przed jakimkolwiek ruchem HTTP testów):
   - liczniki muszą być równe, a kategorie, produkty, kombinacje i zdjęcia niezerowe,
   - tabele danych sklepu (katalog, warianty, zdjęcia, stany, promocje, przewoźnicy, podatki, moduły, CMS, klienci, zamówienia) muszą mieć identyczne `CHECKSUM TABLE`; różnice w `configuration`, `shop_url`, `webservice_account` i tabelach statystyk są oczekiwane i tylko raportowane,
   - pliki `img/p` muszą mieć identyczne ścieżki i zawartość,
   - `ps_configuration` musi być zgodna poza domeną (`PS_SHOP_DOMAIN*`), włączeniem sklepu i wersjami cache CSS/JS, a eksport `export-config.php` identyczny,
   - domena w `ps_shop_url` musi wskazywać `localhost:19443`, a sklep być włączony.
6. **Funkcjonalności** (`deploy/verify/restore-smoke.php` w kontenerze `shop`, żądania przez nginx środowiska): strona główna z nazwą sklepu, kategoria z listą produktów, produkt z selektorem wariantów, plik zdjęcia i miniatura pod przyjaznym adresem, produkt z ceną promocyjną, wyszukiwarka, dodanie wariantu do koszyka, podgląd koszyka, pierwszy krok zamówienia, logowanie do panelu, Webservice API z nowym kluczem.
7. **Raport** Markdown w `docs/RESTORE_VERIFICATION.md` (inna ścieżka: `--report`) i pełny log w `deploy/backups/verify/verify-*.log`. Raport powstaje także po przerwaniu weryfikacji (wynik negatywny z opisem). Raport podaje czas odtwarzania i czas od zera (z przygotowaniem obrazów). Kod wyjścia 0 oznacza wynik pozytywny.

Po weryfikacji odtworzony sklep zostaje uruchomiony pod `https://localhost:19443` (panel `/admin-dev/`, konto administratora z kopii), żeby można go było pokazać. `--cleanup` usuwa kontenery, wolumeny, wygenerowany `verify.env`, `nginx.conf`, certyfikat i archiwum weryfikacji.

Brak produktu z wariantem lub promocji jest błędem, gdy baza zawiera kombinacje lub aktualne ceny promocyjne; pominięciem tylko wtedy, gdy sklep ich nie ma.

Pełny test zakupu E2E na odtworzonym sklepie (opcjonalnie, z aktywnym środowiskiem Pythona z `tests/requirements.txt`):

```powershell
$env:BASE_URL="https://localhost:19443"; python -m pytest -c pytest-e2e.ini
```

| Zmienna | Domyślnie |
|---|---|
| `VERIFY_PROJECT` | `prestashop-restore-verify` (nie może być równy projektowi sklepu źródłowego) |
| `VERIFY_HTTPS_PORT`, `VERIFY_HTTP_PORT`, `VERIFY_MAILPIT_PORT` | `19443`, `19080`, `19025` |
| `RESTORE_TIME_LIMIT` | `600` sekund |

Sklep źródłowy to zawsze ten z `<repo>/.env`, bo z niego robi kopię `backup.sh`.

Z `--archive` porównanie ze sklepem źródłowym jest pomijane, bo sklep mógł zmienić się po wykonaniu kopii. Skrypt sprawdza wtedy liczbę zdjęć z manifestem i konfigurację z `deploy/config/shop-config.json` (`export-config.php --check`).

Wynik ostatniego przebiegu zawiera [raport weryfikacji odtwarzania](RESTORE_VERIFICATION.md). Jest to zapis konkretnego przebiegu, nie potwierdzenie bieżącego stanu.

[Indeks dokumentacji](README.md) · [Główne README](../README.md)
