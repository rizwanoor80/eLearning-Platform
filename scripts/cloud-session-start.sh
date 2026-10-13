#!/usr/bin/env bash
# Cloud-only session start (R194). Exits at once on a local machine.
[ "${CLAUDE_CODE_REMOTE:-}" = "true" ] || exit 0
service postgresql start >/dev/null 2>&1 || true
service redis-server start >/dev/null 2>&1 || true
sudo -u postgres psql -c "ALTER USER postgres PASSWORD 'postgres';" >/dev/null 2>&1 || true
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='elearning'" | grep -q 1 || sudo -u postgres createdb elearning
[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force >/dev/null 2>&1 || true
php artisan migrate --force --no-interaction >/dev/null 2>&1 || true
