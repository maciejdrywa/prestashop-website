FROM prestashop/prestashop:8.2.8-8.1-apache@sha256:5e3f6ddd6fe80ccc466a192e9c8a95c16ebb91857b13518f8dca9a0139ca61a5

# Ustawienia wykonania PHP oraz źródła modułu kopiowane do trwałej instalacji.
COPY config/php.ini /usr/local/etc/php/conf.d/zz-shop.ini
COPY modules/shopcookieinfo /opt/shop-modules/shopcookieinfo
# Inicjalizator kopiuje motyw z obrazu do trwałego wolumenu shop_data.
COPY themes/pandomu /opt/shop-themes/pandomu
# Konfiguracją zarządza init-shop.php. Restart Apache zachowuje ustawienia panelu.
