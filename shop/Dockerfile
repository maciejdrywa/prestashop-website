FROM prestashop/prestashop:8.2.8-8.1-apache@sha256:5e3f6ddd6fe80ccc466a192e9c8a95c16ebb91857b13518f8dca9a0139ca61a5

COPY config/php.ini /usr/local/etc/php/conf.d/zz-shop.ini

COPY config/configure-mail.sh /tmp/init-scripts/10-configure-mail.sh
COPY config/configure-storefront.sh /tmp/init-scripts/20-configure-storefront.sh
COPY config/configure-menu.sh /tmp/init-scripts/30-configure-menu.sh
COPY config/configure-content.sh /tmp/init-scripts/40-configure-content.sh

RUN sed -i 's/\r$//' /tmp/init-scripts/10-configure-mail.sh \
    /tmp/init-scripts/20-configure-storefront.sh /tmp/init-scripts/30-configure-menu.sh /tmp/init-scripts/40-configure-content.sh \
    && chmod +x /tmp/init-scripts/10-configure-mail.sh /tmp/init-scripts/20-configure-storefront.sh /tmp/init-scripts/30-configure-menu.sh /tmp/init-scripts/40-configure-content.sh
