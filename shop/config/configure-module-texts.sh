#!/bin/sh
set -eu

runuser -u www-data -- php /opt/shop/init/configure-module-texts.php
