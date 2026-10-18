#!/usr/bin/env bash
# Cloud-only session start (R194, R212). Exits at once on a local machine.
[ "${CLAUDE_CODE_REMOTE:-}" = "true" ] || exit 0
service postgresql start >/dev/null 2>&1 || true
service redis-server start >/dev/null 2>&1 || true
pg_isready -q || echo "cloud-session-start: PostgreSQL is not accepting connections"
# The role password is the environment's DB_PASSWORD, set only when it is non-empty: an exported but empty value is what
# Laravel sends as an empty password, so defaulting it to "postgres" here would make the role and the application disagree.
# It goes through psql's own literal quoting on stdin (psql does not interpolate variables inside -c), and stderr stays
# hidden: a failed statement would echo it.
if [ -n "${DB_PASSWORD:-}" ]; then
  echo "ALTER USER postgres PASSWORD :'pw';" | sudo -u postgres psql -q -v pw="$DB_PASSWORD" >/dev/null 2>&1 || true
else
  echo "cloud-session-start: DB_PASSWORD is empty or unset, so the postgres role password is left as it is"
fi
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='elearning'" | grep -q 1 || sudo -u postgres createdb elearning
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='elearning_testing'" | grep -q 1 || sudo -u postgres createdb elearning_testing
[ -f .env ] || cp .env.example .env
if [ -f vendor/autoload.php ]; then
  grep -q '^APP_KEY=base64' .env || php artisan key:generate --force --no-interaction 2>&1 | tail -3 || true
  php artisan migrate --force --no-interaction 2>&1 | tail -3 || true
else
  echo "cloud-session-start: vendor/ missing, run composer install then php artisan migrate"
fi
