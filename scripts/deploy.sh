#!/bin/bash
# =============================================================================
# Deploy script for Ribath Backend API (Capistrano-style)
# =============================================================================
# Usage:
#   bash deploy.sh [--branch=BRANCH] [--migrate] [--seed]
#
# Flags:
#   --branch=BRANCH   Git branch to deploy (default: main).
#                     Also accepts: --branch BRANCH
#   --migrate         Run database migrations (php artisan migrate --force).
#                     ALWAYS takes a full pg_dump backup FIRST to backups/.
#                     Without this flag, the deploy skips migrations entirely.
#   --seed            Run database seeders (php artisan db:seed --force).
#                     Use when new permissions/roles/class-levels are seeded.
#   -h, --help        Show this help and exit.
#
# Examples:
#   bash deploy.sh                                       # deploy main, no migrate, no seed
#   bash deploy.sh --branch=002-fee-management           # deploy a feature branch (testing)
#   bash deploy.sh --migrate                             # deploy main + backup + migrate
#   bash deploy.sh --branch=feature/x --migrate --seed   # full deploy of a branch
#
# Structure:
#   /srv/www/ribath-backend/
#   ├── scripts/deploy.sh  # This script (lives outside releases)
#   ├── releases/          # Timestamped release directories
#   ├── current -> releases/YYYYMMDDHHMMSS  (symlink to active release)
#   ├── backups/           # pg_dump backups (auto-created on every --migrate run)
#   └── shared/
#       ├── env/.env       # Production environment file (DB_PASSWORD lives here)
#       └── storage/       # Persistent storage (logs, cache, sessions, app)
#
# First-time setup: run scripts/setup.sh instead (creates dirs, DB, .env, nginx)
# =============================================================================

set -euo pipefail

# --- Configuration ---
BASE_DIR="/srv/www/ribath-backend"
RELEASES_DIR="$BASE_DIR/releases"
SHARED_DIR="$BASE_DIR/shared"
CURRENT_LINK="$BASE_DIR/current"
BACKUPS_DIR="$BASE_DIR/backups"
REPO_URL="git@github-ribath-backend:ak-rocksdev/ribath-backend.git"
DB_NAME="ribath_app_prod"
DB_USER="ak_rocks"
DB_HOST="127.0.0.1"
KEEP_RELEASES=10
PHP_BIN="php"
COMPOSER_BIN="composer"

# --- Defaults ---
BRANCH="main"
RUN_MIGRATE=false
RUN_SEED=false

show_help() {
    sed -n '/^# Usage:/,/^# ====/p' "$0" | sed 's/^#//' | head -n -1
    exit 0
}

# --- Parse arguments ---
while [ $# -gt 0 ]; do
    case "$1" in
        --branch=*)
            BRANCH="${1#--branch=}"
            shift
            ;;
        --branch)
            if [ -z "${2:-}" ] || [[ "$2" == --* ]]; then
                echo "ERROR: --branch requires a value (e.g. --branch=main)"
                exit 1
            fi
            BRANCH="$2"
            shift 2
            ;;
        --migrate)
            RUN_MIGRATE=true
            shift
            ;;
        --seed)
            RUN_SEED=true
            shift
            ;;
        -h|--help)
            show_help
            ;;
        *)
            echo "ERROR: Unknown argument: $1"
            echo "Run 'bash $0 --help' for usage."
            exit 1
            ;;
    esac
done

if [ -z "$BRANCH" ]; then
    echo "ERROR: --branch cannot be empty"
    exit 1
fi

# Timestamp for this release
TIMESTAMP=$(date +%Y%m%d%H%M%S)
RELEASE_DIR="$RELEASES_DIR/$TIMESTAMP"

echo "============================================="
echo "  Deploying Ribath Backend API"
echo "  Branch:   $BRANCH"
echo "  Release:  $TIMESTAMP"
echo "  Migrate:  $RUN_MIGRATE"
echo "  Seed:     $RUN_SEED"
echo "============================================="
echo ""

# --- Step 1: Clone release ---
echo "[1/11] Cloning $BRANCH into $RELEASE_DIR..."
git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$RELEASE_DIR"
echo "      Commit: $(cd "$RELEASE_DIR" && git log --oneline -1)"

# --- Step 2: Link shared .env ---
echo "[2/11] Linking shared environment file..."
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
echo "[3/11] Linking shared storage directory..."
rm -rf "$RELEASE_DIR/storage"
ln -sfn "$SHARED_DIR/storage" "$RELEASE_DIR/storage"
echo "      Linked storage/"

# --- Step 4: Install Composer dependencies ---
echo "[4/11] Installing Composer dependencies..."
cd "$RELEASE_DIR"
$COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction --prefer-dist
echo "      Dependencies installed"

# --- Step 4b: Install Node dependencies (puppeteer / Browsershot Chromium) ---
if [ -f "$RELEASE_DIR/package.json" ]; then
    echo "[4b/11] Installing Node dependencies..."
    cd "$RELEASE_DIR"
    export PUPPETEER_CACHE_DIR="${PUPPETEER_CACHE_DIR:-$SHARED_DIR/puppeteer-cache}"
    mkdir -p "$PUPPETEER_CACHE_DIR"
    npm ci --omit=dev --no-audit --no-fund
    echo "      Node deps installed (cache: $PUPPETEER_CACHE_DIR)"
fi

# --- Step 5: Backup + Migrate (conditional on --migrate) ---
if [ "$RUN_MIGRATE" = true ]; then
    echo "[5/11] Backing up database before migration..."
    mkdir -p "$BACKUPS_DIR"
    BRANCH_TAG=$(echo "$BRANCH" | tr '/' '_')
    BACKUP_FILE="$BACKUPS_DIR/${DB_NAME}_pre_migrate_${BRANCH_TAG}_${TIMESTAMP}.sql"

    # Pull DB_PASSWORD from shared .env so the secret never lives in this script.
    DB_PASSWORD=$(grep -E '^DB_PASSWORD=' "$SHARED_DIR/env/.env" | head -1 | cut -d'=' -f2-)
    DB_PASSWORD="${DB_PASSWORD%\"}"
    DB_PASSWORD="${DB_PASSWORD#\"}"
    if [ -z "$DB_PASSWORD" ]; then
        echo "      ERROR: DB_PASSWORD not found in $SHARED_DIR/env/.env — aborting before migration"
        rm -rf "$RELEASE_DIR"
        exit 1
    fi

    PGPASSWORD="$DB_PASSWORD" pg_dump -h "$DB_HOST" -U "$DB_USER" \
        -d "$DB_NAME" --format=plain --no-owner --no-acl \
        -f "$BACKUP_FILE"
    echo "      Backup written: $BACKUP_FILE ($(du -h "$BACKUP_FILE" | cut -f1))"

    echo "      Running database migrations..."
    cd "$RELEASE_DIR"
    $PHP_BIN artisan migrate --force
    echo "      Migrations complete"
else
    echo "[5/11] Skipping migrations (pass --migrate to run them)"
fi

if [ "$RUN_SEED" = true ]; then
    echo "      Running seeders..."
    cd "$RELEASE_DIR"
    $PHP_BIN artisan db:seed --force
    echo "      Seeders complete"
fi

# --- Step 6: Cache config, routes, views ---
echo "[6/11] Caching configuration..."
cd "$RELEASE_DIR"
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
$PHP_BIN artisan event:cache
echo "      Config, routes, views, events cached"

# --- Step 7: Storage link ---
echo "[7/11] Creating storage link..."
$PHP_BIN artisan storage:link --force 2>/dev/null || true
echo "      Storage linked"

# --- Step 8: Switch current symlink (atomic) ---
echo "[8/11] Switching current symlink..."
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

# --- Step 9: Restart long-running workers ---
# Reverb and the queue worker run under Supervisor as www-data (programs
# ribath-reverb / ribath-queue, autorestart=true). The deploy user has no sudo
# for supervisorctl, so signal them the Laravel way: both commands set a restart
# flag in the cache (CACHE_STORE=database), the processes exit gracefully and
# Supervisor starts them again from the new `current` release.
echo "[9/11] Signalling Reverb and queue workers to restart..."
if $PHP_BIN "$RELEASE_DIR/artisan" reverb:restart; then
    echo "      Reverb restart signalled"
else
    echo "      WARNING: reverb:restart failed — run: sudo supervisorctl restart ribath-reverb"
fi
if $PHP_BIN "$RELEASE_DIR/artisan" queue:restart; then
    echo "      Queue restart signalled"
else
    echo "      WARNING: queue:restart failed — run: sudo supervisorctl restart ribath-queue"
fi

# --- Step 10: Cleanup old releases ---
echo "[10/11] Cleaning up old releases (keeping last $KEEP_RELEASES)..."
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
echo "  Branch:   $BRANCH"
echo "  Release:  $TIMESTAMP"
echo "  Commit:   $(cd "$RELEASE_DIR" && git log --oneline -1)"
echo "  Served:   $CURRENT_LINK -> $RELEASE_DIR"
echo "  Time:     $(date)"
echo "============================================="
