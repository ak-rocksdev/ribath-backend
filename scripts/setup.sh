#!/bin/bash
# =============================================================================
# First-time server setup for Ribath Backend API
# =============================================================================
# Run this ONCE on a fresh server before the first deploy.
# After this, use deploy.sh for all subsequent deployments.
#
# Usage:   bash setup.sh
#
# Prerequisites:
#   - Ubuntu/Debian server with root or sudo access
#   - PHP 8.2+ with extensions: pgsql, pdo_pgsql, mbstring, xml, curl, zip
#   - Composer installed globally
#   - PostgreSQL installed and running
#   - Nginx installed
#   - Git installed
# =============================================================================

set -euo pipefail

# --- Configuration ---
BASE_DIR="/srv/www/ribath-backend"
SHARED_DIR="$BASE_DIR/shared"
DB_NAME="ribath_app_prod"
DB_USER="ribath_user"
API_DOMAIN="api.yourdomain.com"          # ← Change this
FRONTEND_DOMAIN="yourdomain.com"         # ← Change this
PHP_VERSION="8.2"                        # ← Change if different
PHP_BIN="php"
COMPOSER_BIN="composer"

echo "============================================="
echo "  First-time Setup: Ribath Backend API"
echo "============================================="
echo ""

# --- Step 1: Create directory structure ---
echo "[1/8] Creating directory structure..."
sudo mkdir -p "$BASE_DIR"/{releases,scripts}
sudo mkdir -p "$SHARED_DIR"/{env,storage/{app/public,framework/{cache/data,sessions,views},logs}}
sudo chown -R www-data:www-data "$BASE_DIR"
sudo chmod -R 775 "$SHARED_DIR/storage"
echo "      Created:"
echo "        $BASE_DIR/releases/"
echo "        $SHARED_DIR/env/"
echo "        $SHARED_DIR/storage/ (with framework subdirs)"

# --- Step 2: Create PostgreSQL database & user ---
echo ""
echo "[2/8] Setting up PostgreSQL..."
echo "      Creating database user '$DB_USER' and database '$DB_NAME'..."

# Check if user exists
if sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | grep -q 1; then
    echo "      User '$DB_USER' already exists, skipping"
else
    DB_PASSWORD=$(openssl rand -base64 24)
    sudo -u postgres psql -c "CREATE USER $DB_USER WITH PASSWORD '$DB_PASSWORD';"
    echo "      User '$DB_USER' created"
    echo ""
    echo "      ┌─────────────────────────────────────────────┐"
    echo "      │  SAVE THIS — DB password for $DB_USER:      │"
    echo "      │  $DB_PASSWORD  │"
    echo "      └─────────────────────────────────────────────┘"
    echo ""
fi

# Check if database exists
if sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -q 1; then
    echo "      Database '$DB_NAME' already exists, skipping"
else
    sudo -u postgres psql -c "CREATE DATABASE $DB_NAME OWNER $DB_USER;"
    echo "      Database '$DB_NAME' created"
fi

sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE $DB_NAME TO $DB_USER;"

# --- Step 3: Create production .env ---
echo ""
echo "[3/8] Creating production .env..."
if [ -f "$SHARED_DIR/env/.env" ]; then
    echo "      .env already exists at $SHARED_DIR/env/.env"
    echo "      Skipping (edit manually if needed)"
else
    APP_KEY_VALUE=$($PHP_BIN -r "echo 'base64:' . base64_encode(random_bytes(32));")

    cat > "$SHARED_DIR/env/.env" << ENVEOF
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
DB_PASSWORD=${DB_PASSWORD:-CHANGE_ME}

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

    sudo chown www-data:www-data "$SHARED_DIR/env/.env"
    sudo chmod 640 "$SHARED_DIR/env/.env"
    echo "      Created $SHARED_DIR/env/.env with generated APP_KEY"
    echo "      Review and edit production values (especially DB_PASSWORD)"
fi

# --- Step 4: Copy deploy script to server location ---
echo ""
echo "[4/8] Installing deploy script..."
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$SCRIPT_DIR/deploy.sh" ]; then
    sudo cp "$SCRIPT_DIR/deploy.sh" "$BASE_DIR/scripts/deploy.sh"
    sudo chmod +x "$BASE_DIR/scripts/deploy.sh"
    echo "      Copied deploy.sh to $BASE_DIR/scripts/"
else
    echo "      WARNING: deploy.sh not found in $SCRIPT_DIR"
    echo "      Copy it manually after setup"
fi

# --- Step 5: Run first deploy ---
echo ""
echo "[5/8] Running first deployment..."
bash "$BASE_DIR/scripts/deploy.sh"

# --- Step 6: Run seeders ---
echo ""
echo "[6/8] Running database seeders (first-time only)..."
cd "$(readlink -f "$BASE_DIR/current")"
$PHP_BIN artisan db:seed --force
echo "      Seeders complete (school, class levels, roles, permissions, admin user)"

# --- Step 7: Nginx vhost ---
echo ""
echo "[7/8] Creating Nginx configuration..."
NGINX_CONF="/etc/nginx/sites-available/ribath-backend"

if [ -f "$NGINX_CONF" ]; then
    echo "      Nginx config already exists, skipping"
else
    sudo tee "$NGINX_CONF" > /dev/null << NGINXEOF
server {
    listen 80;
    server_name $API_DOMAIN;
    root $BASE_DIR/current/public;

    index index.php;

    charset utf-8;

    # API-only — no static file fallback needed
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

    sudo ln -sfn "$NGINX_CONF" /etc/nginx/sites-enabled/ribath-backend
    sudo nginx -t && sudo systemctl reload nginx
    echo "      Nginx configured for $API_DOMAIN"
    echo "      NOTE: Run 'sudo certbot --nginx -d $API_DOMAIN' for SSL"
fi

# --- Step 8: SSL ---
echo ""
echo "[8/8] SSL certificate..."
if command -v certbot &>/dev/null; then
    echo "      Certbot is available. Run manually:"
    echo "      sudo certbot --nginx -d $API_DOMAIN"
else
    echo "      Certbot not found. Install it:"
    echo "      sudo apt install certbot python3-certbot-nginx"
    echo "      sudo certbot --nginx -d $API_DOMAIN"
fi

# --- Done ---
echo ""
echo "============================================="
echo "  First-time setup complete!"
echo "============================================="
echo ""
echo "  Checklist:"
echo "  [x] Directory structure created"
echo "  [x] PostgreSQL database & user created"
echo "  [x] Production .env generated"
echo "  [x] First deploy executed"
echo "  [x] Seeders ran (roles, permissions, admin, school, class levels)"
echo "  [x] Nginx configured"
echo "  [ ] SSL — run: sudo certbot --nginx -d $API_DOMAIN"
echo "  [ ] Review .env: $SHARED_DIR/env/.env"
echo "  [ ] Test: curl https://$API_DOMAIN/api/v1/auth/login"
echo ""
echo "  For subsequent deploys, run:"
echo "    bash $BASE_DIR/scripts/deploy.sh"
echo "============================================="
