#!/usr/bin/env bash
set -euo pipefail
# Local, isolated cloud runtime. This never connects to the Viratel production shop.
VSP_RUNTIME=/workspace/vsp-runtime
VSP_REPO=/workspace/torob-slaes
mkdir -p "$VSP_RUNTIME"/{debs,root,bin,apt/lists/partial,apt/cache/archives/partial}
if [[ ! -x "$VSP_RUNTIME/root/usr/bin/php8.4" ]]; then
  printf '%s\n' 'deb [signed-by=/usr/share/keyrings/debian-archive-keyring.gpg] https://deb.debian.org/debian trixie main' > "$VSP_RUNTIME/apt/sources.list"
  VSP_APT=(-o Dir::Etc::sourcelist="$VSP_RUNTIME/apt/sources.list" -o Dir::Etc::sourceparts=- -o Dir::Etc::parts=- -o Dir::State::lists="$VSP_RUNTIME/apt/lists" -o Dir::Cache="$VSP_RUNTIME/apt/cache" -o APT::Sandbox::User=agent)
  apt-get "${VSP_APT[@]}" update
  (cd "$VSP_RUNTIME/debs"; apt-get "${VSP_APT[@]}" download php8.4-cli php8.4-common php8.4-mysql php8.4-xml php8.4-mbstring php8.4-curl php8.4-bcmath php8.4-zip libargon2-1 libsodium23 libonig5 libxml2 libzip5 mariadb-server-core mariadb-client-core liburing2 libaio1t64 libfmt10 libpcre2-8-0)
  for VSP_PACKAGE in "$VSP_RUNTIME"/debs/*.deb; do dpkg-deb -x "$VSP_PACKAGE" "$VSP_RUNTIME/root"; done
fi
cat > "$VSP_RUNTIME/php.ini" <<INI
extension_dir=$VSP_RUNTIME/root/usr/lib/php/20240924
extension=mysqlnd
extension=mysqli
extension=pdo
extension=pdo_mysql
extension=ctype
extension=tokenizer
extension=mbstring
extension=iconv
extension=dom
extension=xml
extension=xmlreader
extension=xmlwriter
extension=simplexml
extension=curl
extension=phar
extension=fileinfo
extension=zip
memory_limit=512M
date.timezone=UTC
INI
cat > "$VSP_RUNTIME/bin/php" <<'SH'
#!/bin/sh
export LD_LIBRARY_PATH=/workspace/vsp-runtime/root/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}
exec /workspace/vsp-runtime/root/usr/bin/php8.4 -c /workspace/vsp-runtime/php.ini "$@"
SH
cat > "$VSP_RUNTIME/bin/wp" <<'SH'
#!/bin/sh
exec /workspace/vsp-runtime/bin/php /workspace/vsp-runtime/wp-cli.phar --path=/workspace/vsp-runtime/wordpress "$@"
SH
chmod +x "$VSP_RUNTIME/bin/"{php,wp}
if [[ ! -f "$VSP_RUNTIME/wp-cli.phar" ]]; then curl -fsSL --max-time 60 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$VSP_RUNTIME/wp-cli.phar"; fi
if [[ ! -f "$VSP_RUNTIME/wordpress/wp-load.php" ]]; then git clone --quiet --depth 1 --branch 7.1.3 https://github.com/WordPress/WordPress.git "$VSP_RUNTIME/wordpress"; fi
if [[ ! -f "$VSP_RUNTIME/wordpress/wp-content/plugins/woocommerce/woocommerce.php" ]]; then
  curl -fsSL --max-time 60 https://github.com/woocommerce/woocommerce/releases/download/11.2.1/woocommerce.zip -o "$VSP_RUNTIME/woocommerce.zip"
  unzip -q "$VSP_RUNTIME/woocommerce.zip" -d "$VSP_RUNTIME/wordpress/wp-content/plugins"
fi
export LD_LIBRARY_PATH="$VSP_RUNTIME/root/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
if [[ ! -d "$VSP_RUNTIME/db/mysql" ]]; then "$VSP_RUNTIME/root/usr/bin/mariadb-install-db" --no-defaults --basedir="$VSP_RUNTIME/root/usr" --datadir="$VSP_RUNTIME/db" --auth-root-authentication-method=socket --skip-test-db > "$VSP_RUNTIME/db-init.log" 2>&1; fi
bash "$VSP_REPO/tools/start-runtime.sh" --db-only
"$VSP_RUNTIME/root/usr/bin/mariadb" --no-defaults --socket="$VSP_RUNTIME/mysql.sock" -u agent -e 'CREATE DATABASE IF NOT EXISTS vsp_test;'
if [[ ! -f "$VSP_RUNTIME/wordpress/wp-config.php" ]]; then
 "$VSP_RUNTIME/bin/wp" config create --dbname=vsp_test --dbuser=agent --dbpass='' --dbhost="localhost:$VSP_RUNTIME/mysql.sock" --skip-check --extra-php <<'PHP'
define('WP_ENVIRONMENT_TYPE','local');
define('DISABLE_WP_CRON',true);
define('WP_DEBUG',true);
define('WP_DEBUG_LOG',true);
define('WP_DEBUG_DISPLAY',false);
PHP
fi
if ! "$VSP_RUNTIME/bin/wp" core is-installed; then
 VSP_LOCAL_PASSWORD=$(python3 -c 'import secrets;print(secrets.token_urlsafe(32))')
 "$VSP_RUNTIME/bin/wp" core install --url=http://127.0.0.1:8090 --title='Viratel Local Test' --admin_user=vsp-local --admin_password="$VSP_LOCAL_PASSWORD" --admin_email=local@example.invalid --skip-email
 export VSP_LOCAL_PASSWORD
 python3 - <<'PY'
import json, os
path='/workspace/vsp-runtime/ui-credentials.json'
fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600)
with os.fdopen(fd,'w') as f: json.dump({'username':'vsp-local','password':os.environ['VSP_LOCAL_PASSWORD']},f)
PY
 unset VSP_LOCAL_PASSWORD
fi
mkdir -p "$VSP_RUNTIME/wordpress/wp-content/mu-plugins"
cat > "$VSP_RUNTIME/wordpress/wp-content/mu-plugins/vsp-local-runtime.php" <<'PHP'
<?php
if(wp_get_environment_type()==='local') {
    add_filter('action_scheduler_allow_async_request_runner','__return_false');
}
PHP
"$VSP_RUNTIME/bin/wp" option update siteurl http://127.0.0.1:8090
"$VSP_RUNTIME/bin/wp" option update home http://127.0.0.1:8090
if [[ ! -e "$VSP_RUNTIME/wordpress/wp-content/plugins/viratel-smart-pricing" ]]; then ln -s "$VSP_REPO/viratel-smart-pricing" "$VSP_RUNTIME/wordpress/wp-content/plugins/viratel-smart-pricing"; fi
"$VSP_RUNTIME/bin/wp" plugin activate woocommerce viratel-smart-pricing
"$VSP_RUNTIME/bin/wp" option update woocommerce_currency IRT
"$VSP_RUNTIME/bin/php" -v
