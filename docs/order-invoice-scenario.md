# Status zamówienia i demonstracyjna faktura VAT

## Scenariusz

| Płatność / zdarzenie | Status | Opłacone | Faktura |
|---|---|---|---|
| Zamówienie przelewem | Oczekiwanie na płatność przelewem | Nie | Niedostępna |
| Zamówienie za pobraniem | Oczekiwanie na płatność przy odbiorze | Nie | Niedostępna |
| Administrator potwierdza otrzymanie pieniędzy | Płatność zaakceptowana | Tak | Generowana i dostępna do pobrania |

Wybór przelewu lub pobrania nie potwierdza otrzymania pieniędzy. W projekcie
administrator ręcznie symuluje ich otrzymanie, również po pobraniu. Nie wysyłamy
rzeczywistych przelewów i nie podłączamy operatora płatności. Stan „Płatność
zaakceptowana” używa natywnego `OrderHistory`: zapisuje płatność, tworzy fakturę
i wysyła polski mail z załącznikiem PDF przez lokalny Mailpit.

## Odtwarzanie konfiguracji

`deploy/init/configure-orders.php` korzysta z `lib/orders.php`. Jest etapem
`init-shop.php` po konfiguracji płatności oraz hookiem startowym
`shop/config/configure-orders.sh` (numer 26 w obrazie). Najpierw muszą być
zainstalowane moduły przelewu i pobrania, które udostępniają statusy źródłowe.

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/configure-orders.php --dry-run
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/configure-orders.php

Po zmianie Dockerfile obraz wymaga przebudowy:

    docker compose --env-file .env -f deploy/compose.yaml up -d --build shop

Skrypt używa ID z konfiguracji PrestaShop, nie stałych numerów. Aktualizuje te
same trzy statusy, bez ich duplikowania i bez zmiany statusów istniejących
zamówień. Nie resetuje liczników faktur ani nie zmienia ich istniejących numerów.
Prefiks nowej konfiguracji to `#FV/DEMO/`. Dane adresowe wystawcy są jawnie
fikcyjne: Adres demonstracyjny 1, Sklep demonstracyjny, 00-000 Miasto testowe,
Polska. Treść dokumentu i stopka oznaczają charakter demonstracyjny. Nie dodajemy
zmyślonego NIP-u ani nie przedstawiamy dokumentu jako przeznaczonego do rozliczeń.
Te wartości są odtwarzane przez inicjalizację.

Szablon `payment.html` / `payment.txt` pochodzi z pełnego zestawu gotowych maili,
generowanego po polsku przez `configure-mail.php` przy użyciu wybranego motywu
PrestaShop. Obejmuje to również powitanie, oczekiwanie na przelew i potwierdzenie
zamówienia. Mechanizm odpowiada opcji „Generuj e-maile” w panelu administracyjnym.
Strona „Formy płatności” opisuje klientowi moment udostępnienia faktury.

## Ręczna weryfikacja

1. Zaloguj się na konto klienta i złóż zamówienie przelewem.
2. W „Historii i szczegółach zamówień” sprawdź status oczekiwania i brak faktury.
3. W panelu administratora otwórz **Zamówienia → Zamówienia**, wybierz to zamówienie
   i zmień status na **Płatność zaakceptowana**, potwierdzając aktualizację.
4. Odśwież historię klienta. Sprawdź nowy status i pobierz fakturę z odnośnika PDF.
5. Sprawdź produkt, ilość, VAT, dostawę, kwoty netto/brutto i dane demonstracyjne.
6. Powtórz dla pobrania: początkowo ma oczekiwać na płatność, a dopiero ręczne
   potwierdzenie otrzymania pieniędzy udostępnia fakturę.
7. W Mailpit (`http://localhost:8025`) sprawdź polskie potwierdzenie płatności z PDF.

## Testy automatyczne

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/tests/orders-config-test.php
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/tests/orders-invoices-test.php
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/tests/mail-templates-test.php
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/tests/initialization-test.php

Test konfiguracji jest tylko do odczytu. Test zamówień wymaga lokalnego sklepu
`localhost`, SMTP Mailpit i aktywnego pracownika do natywnej zmiany statusu.
Tworzy osobne konto `Test Faktury`, fikcyjny adres i produkt `invoice-qa-*`
o cenie 100 zł brutto. Składa dwa rzeczywiste **zamówienia testowe** przez HTTP,
jedno każdą metodą, z InPostem za 15 zł. Potwierdza początkowe statusy i brak
faktury, wykonuje zmianę statusu przez natywną klasę administratora, sprawdza
zapis płatności 115 zł, status w koncie klienta, link w historii, pobranie PDF
i odmowę pobrania przez anonimową sesję. Nie loguje się do panelu administratora
przez przeglądarkę. Nie używa ani nie zmienia stanów produktów ze scrapera.

**Zamówienia, ich faktury i dane testowe pozostają w bazie do przeglądu**;
test nie usuwa historii finansowej. Na końcu dezaktywuje własny produkt i konto.
Nieudana próba może pozostawić również częściowo wykonany scenariusz QA.
Identyfikatory zamówień i ścieżki pobranych PDF-ów są wypisywane w konsoli.
Pliki są w `/var/www/html/var/import/invoice-qa/`, wewnątrz wolumenu sklepu.
Test nigdy nie zmienia statusów zamówień innych niż utworzone w bieżącej próbie.

Opcjonalny test tekstu i renderowanie PDF-ów wymagają PyMuPDF w developerskim
venv (nie jest zależnością sklepu ani inicjalizacji). Skopiuj wskazane przez test
pliki przez `docker compose ... cp` do ignorowanego katalogu `tmp/pdfs/`, a potem:

    .\venv\Scripts\python.exe -m pip install pymupdf
    .\venv\Scripts\python.exe deploy/init/tests/invoice-pdf-test.py tmp/pdfs/transfer.pdf tmp/pdfs/cod.pdf --render-dir tmp/pdfs/rendered

Test PDF kontroluje polskie znaki, oznaczenie demonstracyjne, wystawcę, stawkę
VAT oraz kwoty fixture: 93,50 zł netto + 21,50 zł VAT = 115,00 zł brutto.
Wygenerowane PNG służą do wizualnej kontroli, nie są commitowane.

## Wykonana weryfikacja (2026-11-16)

- Przelew i pobranie: pełne testy zakończone dla zamówień 14 i 15 w lokalnej bazie.
- Oba PDF-y pobrane przez zalogowanego klienta, sprawdzone tekstowo i wizualnie.
- Cztery polskie wiadomości HTML/TXT sprawdzone w Mailpit.
- Konfiguracja sprawdzona ponownie po przebudowie głównego kontenera.
- Oddzielny projekt Compose z nowymi wolumenami: automatyczna instalacja,
  hook 26 i `orders-config-test.php --fresh` potwierdziły odtworzenie statusów,
  danych wystawcy, ustawień faktury i polskiego maila. Instalator może zawierać
  własne przykładowe zamówienia; ich nie kasujemy. Ten test nie importował ponownie
  całego katalogu ani galerii. Wolumeny głównego sklepu nie były usuwane.
