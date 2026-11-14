#!/bin/sh
set -eu

if [ -r /opt/shop/scraper-results/categories.json ]; then
    runuser -u www-data -- php /opt/shop/init/configure-menu.php
else
    echo 'Menu configuration deferred: categories.json is missing. Provide scraper results and run init-shop.php.'
fi
