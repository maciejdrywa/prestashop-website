#!/bin/sh
set -eu

runuser -u www-data -- php /opt/shop/init/configure-content.php
