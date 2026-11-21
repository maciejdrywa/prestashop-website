# Obraz sklepu

Dockerfile rozszerza przypięty obraz PrestaShop 8.2.8 z PHP 8.1 i Apache. Kopiuje ustawienia `shop/config/php.ini`, motyw oraz własne moduły: cookies (`shopcookieinfo`), paginacji (`shopcategorypagination`), historii cen (`shopomnibus`) i płatności demonstracyjnych (`shopdemopayments`). Konfiguratory synchronizują je z trwałą instalacją; sam restart Apache nie wdraża nowych plików.

Konfigurację sklepu wykonują skrypty `deploy/init`. Restart kontenera zachowuje ustawienia sklepu. W repozytorium śledzimy własne moduły, motyw, konfigurację i definicję obrazu. Pliki bazowej platformy oraz wygenerowane galerie znajdują się w wolumenie `shop_data`.

Szczegóły opisują [architektura](ARCHITECTURE.md) i [wdrożenie](DEPLOYMENT.md).

Polecenia wykonuj z głównego katalogu repozytorium, po [uruchomieniu sklepu](DEPLOYMENT.md).

## Motyw potomny PAN DOMU

Źródła motywu są w `shop/themes/pandomu/`. Dziedziczą zasoby i widoki Classic. PrestaShop ładuje dodatkowo `assets/css/custom.css` i `assets/js/custom.js`. Szablony punktowo rozszerzają Classic, a arkusz wejściowy ładuje style wspólne, nawigację, katalog i proces zakupu.

Dockerfile pakuje motyw do `/opt/shop-themes/pandomu/`. Inicjalizacja kopiuje go do trwałego `/var/www/html/themes/pandomu/` i włącza natywnym poleceniem PrestaShop. Ponowne wykonanie `configure-theme.php` synchronizuje zmienione pliki z aktualnego obrazu. Dziennik wdrożenia śledzi pliki należące do motywu i pozwala usunąć z instalacji pliki wycofane z obrazu. Aktywny motyw pomija ponowną aktywację. Konfigurator wdraża także moduł paginacji, który ustawia 36 produktów na stronę kategorii i ogranicza większe żądania. Edycja konfiguracji YAML powoduje ponowne wczytanie tej konfiguracji przez PrestaShop.

Aby wgrać motyw na już działający sklep, przebuduj obraz i odtwórz usługę `shop`, a potem uruchom konfigurator motywu i treści. Dane i wolumeny pozostają na miejscu.

```sh
docker compose --env-file .env -f deploy/compose.yaml up -d --build shop
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/configure-theme.php
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/configure-theme-content.php
```

Konfigurator treści korzysta z zaimportowanych kategorii i już istniejącej strony CMS „O sklepie”. Ustawia też drzewo kategorii na bieżącą kategorię. Podczas świeżego startu wykonuje się automatycznie po aktywacji produktów.

Baner strony głównej korzysta z bloku „Kategorie” tworzonego przez ten konfigurator. Pobiera adres kategorii „Klamki do drzwi” z istniejącego kafla, a grafikę WebP z motywu. Na sklepie z już skonfigurowanym blokiem do wdrożenia banera wystarczy synchronizacja motywu.

Natywna aktywacja motywu odtwarza typy miniatur. Konfigurator sprawdza ich identyfikatory i definicje przed pierwszą aktywacją, ponieważ wchodzą do odcisków galerii. Instalacja z innymi typami wymaga uzgodnienia ich przed aktywacją.

## Karta produktu i stopka

Karta produktu pokazuje tylko zakładkę „Opis”. Dane `product-details` pozostają w niewidocznym elemencie wymaganym przez Classic do aktualizacji wariantów, ceny i dostępności. Nadpisania motywu wyłączają widoczne szczegóły, udostępnianie, komentarze i odnośniki do opinii oraz blok reassurance („Bezpieczeństwo”, „Dostawa”, „Zasady zakupów”).

Stopka ma spójny styl nagłówka „Twoje konto” i nie renderuje formularza subskrypcji. Zmiany dotyczą szablonów i CSS motywu, a nie usuwania danych komentarzy czy odinstalowania modułów. Wdrożenie zmian motywu wykonuj powyższymi komendami. Moduł BLIK/PayPal synchronizuje odrębnie `configure-payments.php`; szczegóły zawiera [konfiguracja](CONFIGURATION.md#płatności).

Testy modułu cookies opisuje [testowanie](TESTING.md#python-i-javascript), a ustawienia sprzedaży [konfiguracja](CONFIGURATION.md).

[Indeks dokumentacji](README.md) · [Główne README](../README.md)
