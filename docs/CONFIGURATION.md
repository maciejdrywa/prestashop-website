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
