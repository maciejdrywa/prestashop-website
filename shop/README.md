# Obraz sklepu

Dockerfile rozszerza przypięty obraz PrestaShop 8.2.8 z PHP 8.1 i Apache. Kopiuje ustawienia `config/php.ini` i źródła autorskiego modułu `shopcookieinfo`. Wspólny inicjalizator synchronizuje moduł z trwałym katalogiem instalacji.

Konfigurację sklepu wykonują skrypty `deploy/init`. Restart kontenera zachowuje ustawienia sklepu. W repozytorium śledzimy własny moduł, konfigurację i obraz. Pliki bazowej platformy oraz wygenerowane galerie znajdują się w wolumenie `shop_data`.

Szczegóły opisują [architektura](../docs/ARCHITECTURE.md) i [wdrożenie](../docs/DEPLOYMENT.md).
