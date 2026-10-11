#!/usr/bin/env bash
set -euo pipefail
# Separate LOCAL database and copied core; installed plugin is never the checkout symlink.
cd /workspace/torob-slaes
VSP_RUNTIME=/workspace/vsp-runtime
VSP_SITE="$VSP_RUNTIME/package-site"
VSP_PACKAGE=/workspace/torob-slaes/dist/viratel-smart-pricing-0.1.0.zip
test -f "$VSP_PACKAGE"
export LD_LIBRARY_PATH="$VSP_RUNTIME/root/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
python3 - <<'PY'
from pathlib import Path
import shutil
original=Path('/workspace/vsp-runtime/wordpress')
dest=Path('/workspace/vsp-runtime/package-site')
dest.mkdir(exist_ok=True)
for name in ('wp-admin','wp-includes'):
    if not (dest/name).exists(): shutil.copytree(original/name,dest/name)
for p in original.iterdir():
    if p.is_file() and p.name != 'wp-config.php': shutil.copy2(p,dest/p.name)
plugins=dest/'wp-content/plugins'; plugins.mkdir(parents=True,exist_ok=True)
if not (dest/'wp-content/themes').exists(): shutil.copytree(original/'wp-content/themes',dest/'wp-content/themes')
wc=plugins/'woocommerce'
if not wc.exists(): wc.symlink_to(original/'wp-content/plugins/woocommerce',target_is_directory=True)
(dest/'wp-content/mu-plugins').mkdir(exist_ok=True)
(dest/'wp-content/mu-plugins/local.php').write_text("<?php add_filter('action_scheduler_allow_async_request_runner','__return_false');\n")
PY
"$VSP_RUNTIME/root/usr/bin/mariadb" --no-defaults --socket="$VSP_RUNTIME/mysql.sock" -u agent -e 'CREATE DATABASE IF NOT EXISTS vsp_package;'
VSP_CMD=("$VSP_RUNTIME/bin/php" "$VSP_RUNTIME/wp-cli.phar" --path="$VSP_SITE")
if [[ ! -f "$VSP_SITE/wp-config.php" ]]; then
 "${VSP_CMD[@]}" config create --dbname=vsp_package --dbuser=agent --dbpass='' --dbhost="localhost:$VSP_RUNTIME/mysql.sock" --skip-check --extra-php <<'PHP'
define('WP_ENVIRONMENT_TYPE','local');
define('DISABLE_WP_CRON',true);
define('WP_DEBUG',true);
define('WP_DEBUG_LOG',true);
define('WP_DEBUG_DISPLAY',false);
PHP
fi
if ! "${VSP_CMD[@]}" core is-installed; then
 VSP_PACKAGE_PASSWORD=$(python3 -c 'import secrets;print(secrets.token_urlsafe(32))')
 "${VSP_CMD[@]}" core install --url=http://127.0.0.1:8091 --title='VSP ZIP test' --admin_user=vsp-package --admin_password="$VSP_PACKAGE_PASSWORD" --admin_email=package@example.invalid --skip-email
 unset VSP_PACKAGE_PASSWORD
fi
"${VSP_CMD[@]}" plugin activate woocommerce
"${VSP_CMD[@]}" plugin install "$VSP_PACKAGE" --force --activate
"${VSP_CMD[@]}" eval-file tests/Integration/package-smoke.php
"${VSP_CMD[@]}" --skip-plugins=woocommerce eval 'if(class_exists("WooCommerce")){throw new RuntimeException("Dependency not skipped");} if(!has_action("admin_notices")){throw new RuntimeException("Missing dependency notice");} echo "PASS missing WooCommerce boots without fatal\n";'
"${VSP_CMD[@]}" plugin deactivate viratel-smart-pricing
"${VSP_CMD[@]}" plugin install "$VSP_PACKAGE" --force --activate
"${VSP_CMD[@]}" eval-file tests/Integration/package-verify.php
