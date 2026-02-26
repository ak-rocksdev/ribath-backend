#!/bin/bash
# =============================================================================
# Deploy script for Ribath Backend API (Capistrano-style)
# =============================================================================
# Usage:   bash /srv/www/ribath-backend/scripts/deploy.sh
#
# Structure:
#   /srv/www/ribath-backend/
#   ├── scripts/deploy.sh  # This script (lives outside releases)
#   ├── releases/          # Timestamped release directories
#   ├── current -> releases/YYYYMMDDHHMMSS  (symlink to active release)
#   └── shared/
#       ├── env/.env           # Production environment file
#       └── storage/           # Persistent storage (logs, cache, sessions, app)
#
# First-time setup (run once before first deploy):
#   mkdir -p /srv/www/ribath-backend/{releases,shared/env,shared/storage}
#   mkdir -p /srv/www/ribath-backend/shared/storage/{app/public,framework/{cache/data,sessions,views},logs}
#   cp .env.example /srv/www/ribath-backend/shared/env/.env
#   # Then edit /srv/www/ribath-backend/shared/env/.env with production values
#
# Nginx should point to:
#   root /srv/www/ribath-backend/current/public;
# =============================================================================

set -euo pipefail

# --- Configuration ---
BASE_DIR="/srv/www/ribath-backend"
RELEASES_DIR="$BASE_DIR/releases"
SHARED_DIR="$BASE_DIR/shared"
CURRENT_LINK="$BASE_DIR/current"
REPO_URL="https://github.com/ak-rocksdev/ribath-backend.git"
BRANCH="main"
KEEP_RELEASES=5
PHP_BIN="php"        # Change to full path if needed (e.g., /usr/bin/php8.2)
COMPOSER_BIN="composer"

# Timestamp for this release
TIMESTAMP=$(date +%Y%m%d%H%M%S)
RELEASE_DIR="$RELEASES_DIR/$TIMESTAMP"

echo "============================================="
echo "  Deploying Ribath Backend API"
echo "  Release: $TIMESTAMP"
echo "============================================="
echo ""

# --- Step 1: Clone release ---
echo "[1/9] Cloning $BRANCH into $RELEASE_DIR..."
git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$RELEASE_DIR"
echo "      Commit: $(cd "$RELEASE_DIR" && git log --oneline -1)"

# --- Step 2: Link shared .env ---
echo "[2/9] Linking shared environment file..."
if [ -f "$SHARED_DIR/env/.env" ]; then
    ln -sfn "$SHARED_DIR/env/.env" "$RELEASE_DIR/.env"
    echo "      Linked .env"
else
    echo "      ERROR: $SHARED_DIR/env/.env not found!"
    echo "      Create it first: cp .env.example $SHARED_DIR/env/.env"
    rm -rf "$RELEASE_DIR"
    exit 1
fi

# --- Step 3: Link shared storage ---
echo "[3/9] Linking shared storage directory..."
rm -rf "$RELEASE_DIR/storage"
ln -sfn "$SHARED_DIR/storage" "$RELEASE_DIR/storage"
echo "      Linked storage/"

# --- Step 4: Install dependencies ---
echo "[4/9] Installing Composer dependencies..."
cd "$RELEASE_DIR"
$COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction --prefer-dist
echo "      Dependencies installed"

# --- Step 5: Run migrations ---
echo "[5/9] Running database migrations..."
$PHP_BIN artisan migrate --force
echo "      Migrations complete"

# --- Step 6: Cache config, routes, views ---
echo "[6/9] Caching configuration..."
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
$PHP_BIN artisan event:cache
echo "      Config, routes, views, events cached"

# --- Step 7: Storage link ---
echo "[7/9] Creating storage link..."
$PHP_BIN artisan storage:link --force 2>/dev/null || true
echo "      Storage linked"

# --- Step 8: Switch current symlink (atomic) ---
echo "[8/9] Switching current symlink..."
if [ -L "$CURRENT_LINK" ]; then
    PREVIOUS=$(readlink -f "$CURRENT_LINK")
    echo "      Previous: $(basename "$PREVIOUS")"
fi
ln -sfn "$RELEASE_DIR" "$CURRENT_LINK"
echo "      Now:      $TIMESTAMP"

# Restart PHP-FPM to clear opcache
if command -v systemctl &>/dev/null; then
    echo "      Reloading PHP-FPM..."
    sudo systemctl reload php8.2-fpm 2>/dev/null || \
    sudo systemctl reload php-fpm 2>/dev/null || \
    echo "      WARNING: Could not reload PHP-FPM (reload manually if needed)"
fi

# --- Step 9: Cleanup old releases ---
echo "[9/9] Cleaning up old releases (keeping last $KEEP_RELEASES)..."
RELEASE_COUNT=$(ls -1d "$RELEASES_DIR"/*/ 2>/dev/null | wc -l)
if [ "$RELEASE_COUNT" -gt "$KEEP_RELEASES" ]; then
    ls -1d "$RELEASES_DIR"/*/ | sort | head -n -"$KEEP_RELEASES" | while read -r old_release; do
        echo "      Removing $(basename "$old_release")"
        rm -rf "$old_release"
    done
    echo "      Removed old releases"
else
    echo "      Only $RELEASE_COUNT releases, nothing to clean"
fi

# --- Done ---
echo ""
echo "============================================="
echo "  Deployment complete!"
echo "============================================="
echo "  Release:  $TIMESTAMP"
echo "  Commit:   $(cd "$RELEASE_DIR" && git log --oneline -1)"
echo "  Served:   $CURRENT_LINK -> $RELEASE_DIR"
echo "  Time:     $(date)"
echo "============================================="
