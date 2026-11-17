# Obraz sklepu

Dockerfile rozszerza przypięty obraz PrestaShop 8.2.8 z PHP 8.1 i Apache. Kopiuje ustawienia `config/php.ini` i źródła autorskiego modułu `shopcookieinfo`. Wspólny inicjalizator synchronizuje moduł z trwałym katalogiem instalacji.

Konfigurację sklepu wykonują skrypty `deploy/init`. Restart kontenera zachowuje ustawienia sklepu. W repozytorium śledzimy własny moduł, konfigurację i obraz. Pliki bazowej platformy oraz wygenerowane galerie znajdują się w wolumenie `shop_data`.

Szczegóły opisują [architektura](../docs/ARCHITECTURE.md) i [wdrożenie](../docs/DEPLOYMENT.md).

## Motyw potomny PAN DOMU

Źródła motywu są w `themes/pandomu/`. Dziedziczą zasoby i widoki Classic. PrestaShop ładuje dodatkowo `assets/css/custom.css` i `assets/js/custom.js`. Plik `templates/index.tpl` rozszerza jeden blok rodzica przez `parent:index.tpl`. Ukryty znacznik i neutralna właściwość CSS pozwalają sprawdzić dziedziczenie bez zmiany wyglądu.

Dockerfile pakuje motyw do `/opt/shop-themes/pandomu/`. Inicjalizacja kopiuje go do trwałego `/var/www/html/themes/pandomu/` i włącza natywnym poleceniem PrestaShop. Ponowne uruchomienie synchronizuje zmienione pliki z aktualnego obrazu. Dziennik wdrożenia śledzi pliki należące do motywu i pozwala usunąć z instalacji pliki wycofane z obrazu. Aktywny motyw pomija ponowną aktywację. Edycja konfiguracji YAML powoduje ponowne wczytanie tej konfiguracji przez PrestaShop.

Aby wgrać motyw na już działający sklep, przebuduj obraz i odtwórz usługę `shop`, a potem uruchom sam konfigurator. Dane i wolumeny pozostają na miejscu.

```sh
docker compose --env-file .env -f deploy/compose.yaml up -d --build shop
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/configure-theme.php
```

Natywna aktywacja odtwarza typy miniatur. Konfigurator sprawdza ich identyfikatory i definicje przed pierwszą aktywacją, ponieważ wchodzą do odcisków galerii. Instalacja z innymi typami wymaga uzgodnienia ich przed aktywacją.
