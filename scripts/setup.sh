#!/bin/bash
# =============================================================================
# First-time server setup for Ribath Backend API
# =============================================================================
# Run this ONCE on the production server before the first deploy.
# After this, use deploy.sh for all subsequent deployments.
#
# Usage:   bash /srv/www/ribath-backend/scripts/setup.sh
#
# Prerequisites:
#   - Ubuntu 24.04 server with sudo access
#   - PHP 8.2+ with extensions: pgsql, pdo_pgsql, mbstring, xml, curl, zip
#   - PHP-FPM 8.2 running
#   - Composer installed globally
#   - Nginx installed
#   - Git installed
#   - GitHub deploy key configured (github-ribath-backend SSH alias)
#
# Server: 103.157.97.233 (ak_rocks@)
# =============================================================================

set -euo pipefail

# --- Configuration ---
BASE_DIR="/srv/www/ribath-backend"
SHARED_DIR="$BASE_DIR/shared"
DB_NAME="ribath_app_prod"
DB_USER="ak_rocks"
API_DOMAIN="apiribath.hyperscore.cloud"
FRONTEND_DOMAIN="ribath.hyperscore.cloud"
PHP_VERSION="8.2"
PHP_BIN="php"
COMPOSER_BIN="composer"
SUDO_PASS=""

# Helper: run sudo with password if needed
run_sudo() {
    if [ -n "$SUDO_PASS" ]; then
        echo "$SUDO_PASS" | sudo -S "$@" 2>/dev/null
    else
        sudo "$@"
    fi
}

# Prompt for sudo password once
read -s -p "Enter sudo password: " SUDO_PASS
echo ""

# Verify sudo works
if ! echo "$SUDO_PASS" | sudo -S true 2>/dev/null; then
    echo "ERROR: Invalid sudo password"
    exit 1
fi

echo "============================================="
echo "  First-time Setup: Ribath Backend API"
echo "============================================="
echo ""

# --- Step 1: Install PostgreSQL (if not installed) ---
echo "[1/9] Checking PostgreSQL..."
if command -v psql &>/dev/null; then
    echo "      PostgreSQL already installed: $(psql --version)"
else
    echo "      Installing PostgreSQL..."
    run_sudo apt-get update -qq
    run_sudo apt-get install -y -qq postgresql postgresql-contrib
    run_sudo systemctl enable postgresql
    run_sudo systemctl start postgresql
    echo "      PostgreSQL installed: $(psql --version)"
fi

# Verify PostgreSQL is running
if run_sudo systemctl is-active --quiet postgresql; then
    echo "      PostgreSQL is running"
else
    run_sudo systemctl start postgresql
    echo "      PostgreSQL started"
fi

# --- Step 2: Create directory structure ---
echo ""
echo "[2/9] Creating directory structure..."
run_sudo mkdir -p "$BASE_DIR"/{releases,scripts}
run_sudo mkdir -p "$SHARED_DIR"/{env,storage/{app/public,framework/{cache/data,sessions,views},logs}}
run_sudo chown -R www-data:www-data "$BASE_DIR"
run_sudo chmod -R 775 "$SHARED_DIR/storage"
# Allow ak_rocks to write to the deploy directories
run_sudo chown -R ak_rocks:www-data "$BASE_DIR/releases" "$BASE_DIR/scripts"
echo "      Created directory structure at $BASE_DIR"

# --- Step 3: Create PostgreSQL database & user ---
echo ""
echo "[3/9] Setting up PostgreSQL database..."

# Check if user exists
if run_sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | grep -q 1; then
    echo "      User '$DB_USER' already exists"
else
    DB_PASSWORD=$(openssl rand -base64 24)
    run_sudo -u postgres psql -c "CREATE USER $DB_USER WITH PASSWORD '$DB_PASSWORD';"
    echo "      User '$DB_USER' created"
fi

# Check if database exists
if run_sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -q 1; then
    echo "      Database '$DB_NAME' already exists"
else
    run_sudo -u postgres psql -c "CREATE DATABASE $DB_NAME OWNER $DB_USER;"
    echo "      Database '$DB_NAME' created"
fi

run_sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE $DB_NAME TO $DB_USER;" 2>/dev/null
# Grant schema permissions (required for PostgreSQL 15+)
run_sudo -u postgres psql -d "$DB_NAME" -c "GRANT ALL ON SCHEMA public TO $DB_USER;" 2>/dev/null
echo "      Privileges granted"

# If DB_PASSWORD was just generated, save it; otherwise prompt
if [ -z "${DB_PASSWORD:-}" ]; then
    echo ""
    echo "      User '$DB_USER' already existed. Enter existing DB password:"
    read -s -p "      DB Password: " DB_PASSWORD
    echo ""
fi

echo ""
echo "      ┌─────────────────────────────────────────────┐"
echo "      │  DB: $DB_NAME                               │"
echo "      │  User: $DB_USER                             │"
echo "      │  Password: $DB_PASSWORD                     │"
echo "      └─────────────────────────────────────────────┘"

# --- Step 4: Create production .env ---
echo ""
echo "[4/9] Creating production .env..."
if [ -f "$SHARED_DIR/env/.env" ]; then
    echo "      .env already exists, skipping (edit manually if needed)"
else
    APP_KEY_VALUE=$($PHP_BIN -r "echo 'base64:' . base64_encode(random_bytes(32));")

    run_sudo tee "$SHARED_DIR/env/.env" > /dev/null << ENVEOF
APP_NAME="Ribath Masjid Hub"
APP_ENV=production
APP_KEY=$APP_KEY_VALUE
APP_DEBUG=false
APP_URL=https://$API_DOMAIN

APP_LOCALE=id
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=id_ID

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASSWORD

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="noreply@$API_DOMAIN"
MAIL_FROM_NAME="\${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

SANCTUM_STATEFUL_DOMAINS=$FRONTEND_DOMAIN
FRONTEND_URL=https://$FRONTEND_DOMAIN

SANCTUM_TOKEN_EXPIRATION=480
FRONTEND_SESSION_TIMEOUT=60

VITE_APP_NAME="\${APP_NAME}"
ENVEOF

    run_sudo chown www-data:www-data "$SHARED_DIR/env/.env"
    run_sudo chmod 640 "$SHARED_DIR/env/.env"
    echo "      Created .env with generated APP_KEY"
fi

# --- Step 5: Copy deploy script ---
echo ""
echo "[5/9] Installing deploy script..."
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$SCRIPT_DIR/deploy.sh" ]; then
    cp "$SCRIPT_DIR/deploy.sh" "$BASE_DIR/scripts/deploy.sh"
    chmod +x "$BASE_DIR/scripts/deploy.sh"
    echo "      Copied deploy.sh to $BASE_DIR/scripts/"
else
    echo "      WARNING: deploy.sh not found in $SCRIPT_DIR"
    echo "      Copy it manually after setup"
fi

# --- Step 6: Run first deploy ---
echo ""
echo "[6/9] Running first deployment..."
bash "$BASE_DIR/scripts/deploy.sh"

# --- Step 7: Run seeders ---
echo ""
echo "[7/9] Running database seeders (first-time only)..."
cd "$(readlink -f "$BASE_DIR/current")"
$PHP_BIN artisan db:seed --force
echo "      Seeders complete (school, class levels, roles, permissions, admin user)"

# --- Step 8: Nginx vhost ---
echo ""
echo "[8/9] Creating Nginx configuration..."
NGINX_CONF="/etc/nginx/sites-available/$API_DOMAIN.conf"

if [ -f "$NGINX_CONF" ]; then
    echo "      Nginx config already exists, skipping"
else
    run_sudo tee "$NGINX_CONF" > /dev/null << NGINXEOF
server {
    listen 80;
    server_name $API_DOMAIN;
    root $BASE_DIR/current/public;

    index index.php;

    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    access_log /var/log/nginx/ribath-backend-access.log;
    error_log  /var/log/nginx/ribath-backend-error.log;
}
NGINXEOF

    run_sudo ln -sfn "$NGINX_CONF" "/etc/nginx/sites-enabled/$API_DOMAIN.conf"
    if run_sudo nginx -t 2>&1; then
        run_sudo systemctl reload nginx
        echo "      Nginx configured for $API_DOMAIN"
    else
        echo "      ERROR: Nginx config test failed! Fix manually."
    fi
fi

# --- Step 9: SSL ---
echo ""
echo "[9/9] SSL certificate..."
echo "      DNS must point $API_DOMAIN -> $(curl -s ifconfig.me 2>/dev/null || echo 'this server') first"
echo "      Then run: sudo certbot --nginx -d $API_DOMAIN"

# --- Done ---
echo ""
echo "============================================="
echo "  First-time setup complete!"
echo "============================================="
echo ""
echo "  Checklist:"
echo "  [x] PostgreSQL installed and running"
echo "  [x] Database '$DB_NAME' created (user: $DB_USER)"
echo "  [x] Directory structure created"
echo "  [x] Production .env generated"
echo "  [x] First deploy executed"
echo "  [x] Seeders ran (roles, permissions, admin, school, class levels)"
echo "  [x] Nginx configured for $API_DOMAIN"
echo "  [ ] DNS: Point $API_DOMAIN -> $(curl -s ifconfig.me 2>/dev/null || echo 'server IP')"
echo "  [ ] SSL: sudo certbot --nginx -d $API_DOMAIN"
echo "  [ ] Test: curl https://$API_DOMAIN/api/v1/auth/login"
echo ""
echo "  For subsequent deploys:"
echo "    bash $BASE_DIR/scripts/deploy.sh"
echo "============================================="
