#!/usr/bin/env bash
set -euo pipefail
VSP_RUNTIME=/workspace/vsp-runtime
export LD_LIBRARY_PATH="$VSP_RUNTIME/root/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
if ! "$VSP_RUNTIME/root/usr/bin/mariadb" --no-defaults --socket="$VSP_RUNTIME/mysql.sock" -u agent -e 'SELECT 1' >/dev/null 2>&1; then
 nohup "$VSP_RUNTIME/root/usr/sbin/mariadbd" --no-defaults --basedir="$VSP_RUNTIME/root/usr" --datadir="$VSP_RUNTIME/db" --socket="$VSP_RUNTIME/mysql.sock" --pid-file="$VSP_RUNTIME/mysql.pid" --skip-networking --log-error="$VSP_RUNTIME/mysql.log" > "$VSP_RUNTIME/db-process.log" 2>&1 &
fi
for VSP_ATTEMPT in {1..20}; do
 if "$VSP_RUNTIME/root/usr/bin/mariadb" --no-defaults --socket="$VSP_RUNTIME/mysql.sock" -u agent -e 'SELECT 1' >/dev/null 2>&1; then break; fi
 sleep 1
done
"$VSP_RUNTIME/root/usr/bin/mariadb" --no-defaults --socket="$VSP_RUNTIME/mysql.sock" -u agent -e 'SELECT 1' >/dev/null
if [[ "${1:-}" == --db-only ]]; then exit 0; fi
if ! curl -fsS --max-time 5 http://127.0.0.1:8090/wp-login.php -o /dev/null; then
 nohup "$VSP_RUNTIME/bin/php" -S 127.0.0.1:8090 -t "$VSP_RUNTIME/wordpress" > "$VSP_RUNTIME/http.log" 2>&1 &
fi
for VSP_ATTEMPT in {1..20}; do if curl -fsS --max-time 5 http://127.0.0.1:8090/wp-login.php -o /dev/null; then break; fi; sleep 1; done
curl -fsS --max-time 5 http://127.0.0.1:8090/wp-login.php -o /dev/null
"$VSP_RUNTIME/bin/wp" core is-installed
"$VSP_RUNTIME/bin/wp" vsp status
