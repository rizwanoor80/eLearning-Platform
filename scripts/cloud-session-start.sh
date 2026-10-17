#!/usr/bin/env bash
# Cloud-only session start (R194, R212). Exits at once on a local machine.
[ "${CLAUDE_CODE_REMOTE:-}" = "true" ] || exit 0
service postgresql start >/dev/null 2>&1 || true
service redis-server start >/dev/null 2>&1 || true
# The role password is the environment's DB_PASSWORD (default postgres). It goes through psql's own literal quoting
# on stdin (psql does not interpolate variables inside -c), and stderr stays hidden: a failed statement would echo it.
echo "ALTER USER postgres PASSWORD :'pw';" | sudo -u postgres psql -q -v pw="${DB_PASSWORD:-postgres}" >/dev/null 2>&1 || true
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='elearning'" | grep -q 1 || sudo -u postgres createdb elearning
[ -f .env ] || cp .env.example .env
if [ -f vendor/autoload.php ]; then
  grep -q '^APP_KEY=base64' .env || php artisan key:generate --force --no-interaction 2>&1 | tail -3 || true
  php artisan migrate --force --no-interaction 2>&1 | tail -3 || true
else
  echo "cloud-session-start: vendor/ missing, run composer install then php artisan migrate"
fi
