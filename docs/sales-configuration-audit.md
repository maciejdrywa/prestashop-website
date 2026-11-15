# Audyt sprzedaży — konfiguracja przed zmianami płatności

Data: 2026-11-15. PrestaShop 8.2.8, sklep ID 1, Polska, PLN, jednostka masy kg.
Audyt odczytał bazę bez modyfikowania przewoźników, cen, progów ani statusów.

## Płatności przed konfiguracją

Aktywne były `ps_wirepayment`, `ps_cashondelivery` i `ps_checkpayment`.
Każdy miał uprawnienia do Polski, PLN, standardowych grup odwiedzającego,
gościa i klienta oraz referencji czterech przewoźników instalatora.
Właściciel rachunku, dane rachunku i adres banku dla przelewu były puste.
Nie wykryto aktywnej bramki rzeczywistego operatora płatności.

## Dostawa — stan wymagający dalszej konfiguracji

| ID / referencja | Nazwa | Aktywność | Obecne ustawienia |
|---|---|---|---|
| 1 / 1 | Odbiór osobisty | Aktywny | Darmowy; brak limitu 50 kg. |
| 2 / 2 | Dostawa kurierska | Aktywna | Stawka w tabeli dostawy 5,00 zł dla stref 1 i 2; zakresy ceny i masy 0–10000. |
| 3 / 3 | Dostawa ekonomiczna | Nieaktywna | Przedziały ceny 0–50, 50–100 i 100–200; różne opłaty według strefy. |
| 4 / 4 | Dostawa lekkich przesyłek | Nieaktywna | Przedziały masy 0–1, 1–3 i 3–10000; różne opłaty według strefy. |

Są to przewoźnicy instalatora po wcześniejszym spolszczeniu, a nie dwaj nowi
przewoźnicy wymagani w zadaniu. Kwoty tabeli dostawy nie są potwierdzeniem
ostatecznej ceny brutto koszyka: istnieje też opłata obsługowa 2,00 zł.

- `PS_SHIPPING_FREE_PRICE=0`: brak ustawionego progu darmowej dostawy 2000 zł.
- `PS_SHIPPING_FREE_WEIGHT=0`: brak progu darmowej dostawy według masy.
- `PS_SHIPPING_METHOD=1`: domyślne naliczanie według masy.
- `PS_CARRIER_DEFAULT=1`: domyślny jest darmowy odbiór osobisty.
- `max_weight=0` dla wszystkich czterech przewoźników: nie ma ustawionego limitu 50 kg.
- `range_behavior=0`: nie skonfigurowano odrzucania dostawy po przekroczeniu zakresu.

Progi 2000,00/2000,01 zł i 50,000/50,001 kg wymagają osobnych testów po
wdrożeniu nowych przewoźników oraz reguł. Nie wykonano ich w ramach konfiguracji płatności.

## Statusy i faktury

Faktury są włączone (`PS_INVOICE=1`), prefiks to `#FV`, model PDF: `invoice`.

| Status | ID | Opłacone | Generowanie faktury | Załączenie PDF faktury |
|---|---|---|---|---|
| Oczekiwanie na płatność przelewem | 10 | Nie | Nie | Nie |
| Oczekiwanie na płatność przy odbiorze | 13 | Nie | Nie | Nie |
| Płatność zaakceptowana | 2 | Tak | Tak | Tak |

Pozostałe statusy i ich flagi można odczytać przez skrypt audytowy.
Adres, kod pocztowy, miasto i dodatkowe dane wystawcy nie są uzupełnione
w konfiguracji sklepu. Sama flaga włączonych faktur nie potwierdza poprawnego
PDF ani możliwości pobrania w docelowym scenariuszu. Trzeba ustalić moment
udostępnienia faktury, dane demonstracyjnego wystawcy i wykonać test pobrania.

## Wykonany punkt 2 — konfiguracja płatności

- Pozostawiono włączone przelew tradycyjny i pobranie.
- Wyłączono czek. Moduł i historyczne statusy pozostają zainstalowane.
- Ustawiono jawnie testowe instrukcje przelewu bez rzeczywistego numeru rachunku.
- Odtworzono uprawnienia PL/PLN dla odwiedzających, gości, klientów i obecnych
  referencji przewoźników. Nie zmieniono ich cen ani ograniczeń.
- Strona Formy płatności wymienia tylko przelew i pobranie.
- Konfiguracja jest częścią `init-shop.php` oraz hooka startowego
  `25-configure-payments.sh`; nie wymaga ręcznych ustawień w panelu.

Test HTTP potwierdził dokładnie dwa moduły w rzeczywistym kroku płatności
po przejściu logowania, adresu i dostawy, bez zatwierdzania zamówienia.
Dane testowe są sprzątane przez test. Nie testowano w tym punkcie zapisu
zamówienia, zmiany jego statusu ani faktury — to dalsza część zadania.

Po utworzeniu nowych przewoźników ich referencje należy ponownie uwzględnić
w uprawnieniach płatności przez `configure-payments.php`; przyszły etap dostawy
powinien wykonać tę synchronizację po utworzeniu przewoźników.

## Dodanie dwóch nowych przewoźników

Dodano osobne rekordy „InPost” (15,00 zł brutto) i „DPD”
(20,00 zł brutto), z VAT 23%, bez opłaty manipulacyjnej i bez integracji z operatorem.
Konfigurację odtwarza `configure-carriers.php`, `init-shop.php` i hook startowy 24.
Dotychczasowi przewoźnicy, domyślny przewoźnik i globalne progi pozostają bez zmian.
Test koszyka potwierdza obie ceny, dostępność przewoźników, uprawnienia płatności
oraz brak duplikatów po ponownym uruchomieniu. Reguły 2000 zł i 50 kg nadal są do wdrożenia.

## Powtórzenie audytu bieżącej konfiguracji

Z katalogu projektu:

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/audit-sales.php

Skrypt wypisuje aktualną konfigurację jako JSON. Obecność danych bankowych
i danych wystawcy pokazuje wyłącznie jako wartości logiczne, bez ich treści.
Wyłączone moduły płatności są oznaczone `enabled: false`.
