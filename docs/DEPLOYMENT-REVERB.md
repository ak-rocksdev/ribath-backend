# Deploying Laravel Reverb (WebSocket) + Queue Worker on Production

A complete guide for setting up Laravel Reverb WebSocket broadcasting with Supervisor process management on an Ubuntu VPS behind Nginx with HTTPS. Written based on the production deployment of Ribath Backend, but designed to be reusable for any Laravel project.

**Server context for this guide:**

| Item | Value |
|---|---|
| Server | Ubuntu VPS at `103.157.97.233` |
| SSH user | `ak_rocks` |
| API domain | `apiribath.hyperscore.cloud` (HTTPS, Let's Encrypt) |
| Frontend domain | `ribath.hyperscore.cloud` |
| Backend path | `/srv/www/ribath-backend` (Capistrano-style: `releases/`, `current` symlink, `shared/`) |
| Frontend path | `/srv/www/ribath-masjid-hub` (same structure) |
| PHP | 8.2, PHP-FPM via unix socket |
| Database | PostgreSQL |
| Queue driver | `database` |

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Backend .env Configuration](#2-backend-env-configuration)
3. [Frontend .env.production Configuration](#3-frontend-envproduction-configuration)
4. [Supervisor Configuration](#4-supervisor-configuration)
5. [Nginx WebSocket Proxy](#5-nginx-websocket-proxy)
6. [Firewall (UFW)](#6-firewall-ufw)
7. [CORS Configuration](#7-cors-configuration)
8. [Laravel Broadcasting Auth](#8-laravel-broadcasting-auth)
9. [Deploy Script Integration](#9-deploy-script-integration)
10. [Verification / Testing](#10-verification--testing)
11. [Troubleshooting](#11-troubleshooting)
12. [Adapting for Other Projects](#12-adapting-for-other-projects)

---

## 1. Architecture Overview

Laravel Reverb is a first-party WebSocket server for Laravel. It enables real-time broadcasting of events from the backend to connected browser (and mobile) clients. Here is the full data flow:

```
┌──────────────────────────────────────────────────────────────────────────┐
│  Laravel App                                                             │
│                                                                          │
│  Controller / Service                                                    │
│       │                                                                  │
│       ▼                                                                  │
│  event(new SomeEvent($data))                                             │
│       │                                                                  │
│       │  Event implements ShouldBroadcast                                │
│       │  → Serialized and pushed to the jobs table (database queue)      │
│       ▼                                                                  │
│  ┌─────────────┐         ┌──────────────────┐                            │
│  │ jobs table   │────────▶│  Queue Worker     │                           │
│  │ (database)   │         │  (Supervisor)     │                           │
│  └─────────────┘         └────────┬─────────┘                            │
│                                   │                                      │
│                                   │  Worker deserializes event,          │
│                                   │  sends payload to Reverb             │
│                                   ▼                                      │
│                          ┌──────────────────┐                            │
│                          │  Reverb Server    │                           │
│                          │  127.0.0.1:6001   │                           │
│                          │  (Supervisor)     │                           │
│                          └────────┬─────────┘                            │
└───────────────────────────────────┼──────────────────────────────────────┘
                                    │
                                    │  Internal (localhost only)
                                    ▼
                          ┌──────────────────┐
                          │  Nginx            │
                          │  :443 (HTTPS)     │
                          │  /app → :6001     │
                          │  /apps → :6001    │
                          └────────┬─────────┘
                                    │
                                    │  WSS (WebSocket Secure)
                                    ▼
                          ┌──────────────────┐
                          │  Browser Client   │
                          │  (Laravel Echo)   │
                          │  connects to WSS  │
                          │  via Nginx :443   │
                          └──────────────────┘
```

**Key points:**

- **Reverb listens on `127.0.0.1:6001`** -- it is NOT exposed to the internet. It only accepts connections from localhost.
- **Nginx reverse-proxies** the `/app` and `/apps` URL paths to Reverb on port 6001, performing the HTTP-to-WebSocket upgrade.
- **Frontend connects via WSS** (WebSocket Secure) through Nginx on port 443, never directly to port 6001.
- **Private channels** are authenticated via the `/broadcasting/auth` endpoint, which uses Sanctum Bearer token authentication.
- **Queue worker** is required because `ShouldBroadcast` events are dispatched through the queue. Without a running worker, events will pile up in the `jobs` table and never reach Reverb. (Use `ShouldBroadcastNow` to bypass the queue for testing.)

---

## 2. Backend .env Configuration

Add these variables to the production `.env` file (located at `/srv/www/ribath-backend/shared/.env`):

```env
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database

REVERB_APP_ID=ribath-prod
REVERB_APP_KEY=your-generated-app-key-here
REVERB_APP_SECRET=your-generated-app-secret-here
REVERB_HOST="apiribath.hyperscore.cloud"
REVERB_PORT=6001
REVERB_SERVER_PORT=6001
REVERB_SCHEME=http
```

Generate a random key and secret:

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

Use the first output as `REVERB_APP_KEY` and the second as `REVERB_APP_SECRET`.

After updating `.env`, clear the config cache:

```bash
cd /srv/www/ribath-backend/current
php artisan config:cache
```

### Critical Gotcha: REVERB_HOST Must Be the Public Domain

**`REVERB_HOST` MUST be set to the public domain name (`apiribath.hyperscore.cloud`), NOT `127.0.0.1`.**

Reverb validates the `Host` header of incoming WebSocket connections against the configured `REVERB_HOST`. When a browser connects through Nginx, the `Host` header is `apiribath.hyperscore.cloud`. If `REVERB_HOST` is set to `127.0.0.1`, Reverb will reject the connection with a **500 Internal Server Error** because the hostnames do not match.

### Why REVERB_SCHEME is `http`

Reverb itself does **not** handle TLS termination. Nginx handles HTTPS/SSL on port 443 and proxies plain HTTP/WS traffic to Reverb on port 6001. So from Reverb's perspective, the connection is unencrypted HTTP -- the encryption happens at the Nginx layer.

---

## 3. Frontend .env.production Configuration

Add these to the frontend `.env.production` (or `.env` used for production builds):

```env
VITE_REVERB_APP_KEY="your-generated-app-key-here"
VITE_REVERB_HOST=apiribath.hyperscore.cloud
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

**Key details:**

- **`VITE_REVERB_APP_KEY`** must be the same value as `REVERB_APP_KEY` in the backend `.env`.
- **`VITE_REVERB_PORT` is `443`**, not `6001`. The frontend connects through Nginx on the standard HTTPS port. Nginx then proxies to Reverb on 6001 internally.
- **`VITE_REVERB_SCHEME` is `https`** because the browser connects via WSS (WebSocket Secure) through the Nginx TLS termination.
- **Vite bakes `VITE_*` variables at build time.** Changing these values requires a full frontend rebuild (`npm run build`) and redeployment. They are NOT read at runtime.

---

## 4. Supervisor Configuration

Supervisor manages two long-running processes: the Reverb WebSocket server and the queue worker. Both must be running at all times and restart automatically if they crash.

### Install Supervisor

```bash
sudo apt install supervisor
```

### Create Reverb Process Config

```bash
sudo tee /etc/supervisor/conf.d/ribath-reverb.conf << "EOF"
[program:ribath-reverb]
process_name=%(program_name)s
command=php /srv/www/ribath-backend/current/artisan reverb:start --host=127.0.0.1 --port=6001
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/srv/www/ribath-backend/shared/storage/logs/reverb.log
stopwaitsecs=3600
EOF
```

### Create Queue Worker Config

```bash
sudo tee /etc/supervisor/conf.d/ribath-queue.conf << "EOF"
[program:ribath-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /srv/www/ribath-backend/current/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/srv/www/ribath-backend/shared/storage/logs/queue-worker.log
stopwaitsecs=3600
EOF
```

**Note on heredoc syntax:** Use `<< "EOF"` (double-quoted), not `<< 'EOF'` (single-quoted). With `sudo tee`, single-quoted EOF can cause issues in some shell environments.

### Load and Start

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

Both processes should show `RUNNING`:

```
ribath-reverb                    RUNNING   pid 12345, uptime 0:00:05
ribath-queue:ribath-queue_00     RUNNING   pid 12346, uptime 0:00:05
```

### Useful Supervisor Commands

```bash
# Restart individual processes
sudo supervisorctl restart ribath-reverb
sudo supervisorctl restart ribath-queue

# Stop / start
sudo supervisorctl stop ribath-reverb
sudo supervisorctl start ribath-reverb

# Check status of all processes
sudo supervisorctl status

# Tail logs in real time
tail -f /srv/www/ribath-backend/shared/storage/logs/reverb.log
tail -f /srv/www/ribath-backend/shared/storage/logs/queue-worker.log
```

---

## 5. Nginx WebSocket Proxy

The API Nginx config must include `/app` and `/apps` location blocks that proxy to Reverb. **These blocks MUST appear BEFORE the catch-all `location /` block.** This is critical because `location /` with `try_files` will match `/app` first and send it to PHP-FPM, which returns a 404.

### Full Working Nginx Config

File: `/etc/nginx/sites-available/apiribath.hyperscore.cloud` (or wherever your site config lives)

```nginx
server {
    server_name apiribath.hyperscore.cloud;
    root /srv/www/ribath-backend/current/public;
    index index.php;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    # ─── WebSocket proxy for Laravel Reverb ─────────────────────────
    # MUST be BEFORE location / to prevent try_files from catching these paths
    location /app {
        proxy_pass http://127.0.0.1:6001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
        proxy_send_timeout 60s;
    }

    location /apps {
        proxy_pass http://127.0.0.1:6001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # ─── Standard Laravel routing ────────────────────────────────────
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # ─── SSL (managed by Certbot) ────────────────────────────────────
    listen 443 ssl;
    ssl_certificate /etc/letsencrypt/live/apiribath.hyperscore.cloud/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/apiribath.hyperscore.cloud/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;
}

server {
    if ($host = apiribath.hyperscore.cloud) {
        return 301 https://$host$request_uri;
    }

    listen 80;
    server_name apiribath.hyperscore.cloud;
    return 404;
}
```

### Apply the Config

```bash
sudo nginx -t && sudo systemctl reload nginx
```

**Why both `/app` and `/apps`?**
- `/app` is the WebSocket endpoint used by Pusher-protocol clients (Laravel Echo connects here).
- `/apps` is used by Reverb's internal API for application management and statistics.

---

## 6. Firewall (UFW)

Port 6001 does **NOT** need to be opened in the firewall. Reverb binds to `127.0.0.1:6001`, which means it only accepts connections from localhost. All external WebSocket traffic arrives through Nginx on port 443 (HTTPS), which proxies internally to port 6001.

The only ports that need to be open:

```bash
sudo ufw status

# Required open ports:
# 22/tcp   - SSH
# 80/tcp   - HTTP (redirects to HTTPS)
# 443/tcp  - HTTPS (serves API + proxies WebSocket)
```

**Do not** run `sudo ufw allow 6001`. Exposing Reverb directly to the internet bypasses Nginx TLS termination and authentication.

---

## 7. CORS Configuration

The Laravel CORS config at `config/cors.php` must include `broadcasting/auth` in the `paths` array. This endpoint is called by the frontend (from a different origin) when authenticating private/presence channels.

```php
'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],
```

Without this, the browser will block the authentication request for private channels with a CORS error.

---

## 8. Laravel Broadcasting Auth

The `bootstrap/app.php` file must register broadcasting routes with Sanctum authentication middleware:

```php
->withBroadcasting(
    __DIR__.'/../routes/channels.php',
    ['middleware' => ['auth:sanctum']],
)
```

This registers the `/broadcasting/auth` endpoint and protects it with Sanctum token authentication. When the frontend subscribes to a private channel, Laravel Echo sends the Bearer token to this endpoint, which verifies the user and returns an auth signature.

Channel authorization logic is defined in `routes/channels.php`:

```php
// Example: authorize a user to listen on a private channel
Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return $user->id === (int) $userId;
});
```

---

## 9. Deploy Script Integration

The deploy script (`scripts/deploy.sh` or equivalent) must restart Supervisor processes after each deployment. This ensures Reverb and the queue worker load the new release code after the `current` symlink is switched.

Add this step near the end of the deploy script, after the symlink switch and `config:cache`:

```bash
# --- Step 9: Restart Supervisor workers ---
echo "Restarting Supervisor workers..."
if command -v supervisorctl &>/dev/null; then
    sudo supervisorctl restart ribath-reverb 2>/dev/null
    sudo supervisorctl restart ribath-queue 2>/dev/null
    echo "Supervisor workers restarted."
else
    echo "WARNING: supervisorctl not found. Reverb and queue worker were NOT restarted."
fi
```

**Why this is needed:** The Supervisor configs point to `/srv/www/ribath-backend/current/artisan`, and `current` is a symlink to the latest release directory. After a deploy switches the symlink, the running Reverb and queue worker processes still reference the old release in memory. Restarting them forces them to resolve the symlink again and load the new code.

---

## 10. Verification / Testing

After completing the setup, verify each component works.

### Check Supervisor Processes

```bash
sudo supervisorctl status
```

Expected output:

```
ribath-reverb                    RUNNING   pid 12345, uptime 0:05:30
ribath-queue:ribath-queue_00     RUNNING   pid 12346, uptime 0:05:30
```

### Test Reverb is Listening

```bash
curl -s http://127.0.0.1:6001
```

This returns `Not found.` -- **this is NORMAL**. Reverb is a WebSocket server, not an HTTP server. A plain HTTP GET request is not a valid WebSocket handshake, so Reverb rejects it.

### Test WebSocket Handshake Directly (Bypassing Nginx)

```bash
curl -sv -H 'Upgrade: websocket' -H 'Connection: upgrade' \
  -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
  -H 'Sec-WebSocket-Version: 13' \
  -H 'Host: apiribath.hyperscore.cloud' \
  http://127.0.0.1:6001/app/YOUR_REVERB_APP_KEY?protocol=7 2>&1 | grep 'HTTP/'
```

Expected:

```
< HTTP/1.1 101 Switching Protocols
```

Replace `YOUR_REVERB_APP_KEY` with the actual value from `.env`.

### Test WebSocket Through Nginx (The Real Path)

```bash
curl -sv -H 'Upgrade: websocket' -H 'Connection: upgrade' \
  -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
  -H 'Sec-WebSocket-Version: 13' \
  --http1.1 \
  https://apiribath.hyperscore.cloud/app/YOUR_REVERB_APP_KEY?protocol=7 2>&1 | grep 'HTTP/'
```

Expected:

```
< HTTP/1.1 101 Switching Protocols
```

Note the `--http1.1` flag -- this is required because curl defaults to HTTP/2 for HTTPS, but WebSocket upgrade only works over HTTP/1.1.

### Check Logs

```bash
tail -20 /srv/www/ribath-backend/shared/storage/logs/reverb.log
tail -20 /srv/www/ribath-backend/shared/storage/logs/queue-worker.log
```

### Test from the Browser Console

Open the frontend in a browser, open DevTools console, and check for WebSocket connection messages from Laravel Echo. In the Network tab, filter by "WS" to see the WebSocket connection to `wss://apiribath.hyperscore.cloud/app/...`.

---

## 11. Troubleshooting

These are real issues encountered during production deployment and their fixes.

### 1. WebSocket 500 Internal Server Error

**Symptom:** Browser DevTools shows `WebSocket connection failed: HTTP 500` when trying to connect.

**Cause:** `REVERB_HOST` in `.env` was set to `127.0.0.1` instead of the public domain.

**Why:** Reverb validates the `Host` header of incoming WebSocket connections. The browser sends `Host: apiribath.hyperscore.cloud` (via Nginx), but Reverb expects `Host: 127.0.0.1`. The mismatch causes a 500 error.

**Fix:** Set `REVERB_HOST="apiribath.hyperscore.cloud"` in `.env`, then `php artisan config:cache` and `sudo supervisorctl restart ribath-reverb`.

### 2. WebSocket 404 Through Nginx

**Symptom:** WebSocket connects fine directly to `127.0.0.1:6001` but returns 404 through `https://apiribath.hyperscore.cloud/app/...`.

**Cause:** The `/app` and `/apps` proxy location blocks are missing from the Nginx config, or they are placed AFTER `location /`. The catch-all `location /` block with `try_files` matches `/app` first and sends it to PHP-FPM, which has no route for `/app` and returns 404.

**Fix:** Add the `/app` and `/apps` location blocks to Nginx config and ensure they appear BEFORE `location /`. See [Section 5](#5-nginx-websocket-proxy). Then: `sudo nginx -t && sudo systemctl reload nginx`.

### 3. Firefox CORS Error on Login (Service Worker Related)

**Symptom:** Login works in Chrome but fails in Firefox with a CORS error.

**Cause:** A service worker (`sw.js`) was intercepting cross-origin POST requests (e.g., the login API call). The SW's fetch handler was applying caching logic to cross-origin requests, which stripped CORS headers.

**Fix:** Add an origin check to the service worker's fetch handler to skip cross-origin requests:

```javascript
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    // Skip cross-origin requests — let the browser handle CORS normally
    if (url.origin !== self.location.origin) return;
    // ... rest of caching logic
});
```

### 4. Firefox Stale Chunk Loading Error (Service Worker Cache)

**Symptom:** After a new frontend deploy, Firefox loads stale JS chunks from a previous build, causing module import errors or blank pages. Chrome works fine.

**Cause:** An old service worker with a Cache First strategy was serving cached JS chunks from the previous build. Even clearing the browser cache does NOT unregister service workers.

**Fix:** Manually unregister the service worker:
- Firefox: navigate to `about:serviceworkers` and click "Unregister" for the domain
- Or from DevTools: Application/Storage tab > Service Workers > Unregister
- After unregistering, hard-refresh the page

**Prevention:** Implement a service worker versioning strategy, or use Network First for JS/CSS assets.

### 5. ShouldBroadcast Events Not Sending

**Symptom:** Events implement `ShouldBroadcast` but are never received by connected clients.

**Cause:** The queue driver is `database` but no queue worker is running. `ShouldBroadcast` events are dispatched to the queue and require a worker to process them.

**Fix (production):** Start the queue worker via Supervisor (see [Section 4](#4-supervisor-configuration)).

**Fix (quick testing):** Change the event to implement `ShouldBroadcastNow` instead of `ShouldBroadcast`. This bypasses the queue and sends immediately. Do not use this in production for high-throughput events.

### 6. Supervisor Config Heredoc Syntax Issues

**Symptom:** `sudo tee` command with heredoc creates an empty or malformed config file.

**Cause:** Using single-quoted EOF (`<< 'EOF'`) with `sudo tee` can cause shell interpretation issues in some environments.

**Fix:** Use double-quoted EOF (`<< "EOF"`):

```bash
# Correct
sudo tee /etc/supervisor/conf.d/ribath-reverb.conf << "EOF"
[program:ribath-reverb]
...
EOF

# May cause issues
sudo tee /etc/supervisor/conf.d/ribath-reverb.conf << 'EOF'
...
EOF
```

### 7. "Not found." Response from curl to Reverb

**Symptom:** Running `curl http://127.0.0.1:6001` returns `Not found.`

**This is normal.** Reverb is a WebSocket server. A plain HTTP GET is not a valid WebSocket handshake request. Reverb responds with "Not found." for any non-WebSocket request. To test Reverb properly, use the WebSocket handshake headers as shown in [Section 10](#10-verification--testing).

---

## 12. Adapting for Other Projects

To add Reverb and queue workers to a new Laravel project (e.g., `hst-admin`), follow this checklist. Replace `ribath` with your project name throughout.

### Quick Setup Checklist

1. **Install Reverb in your Laravel project:**
   ```bash
   composer require laravel/reverb
   php artisan install:broadcasting
   ```

2. **Configure backend `.env`:**
   ```env
   BROADCAST_CONNECTION=reverb
   QUEUE_CONNECTION=database
   REVERB_APP_ID=your-project-prod
   REVERB_APP_KEY=<generate with: php -r "echo bin2hex(random_bytes(32));">
   REVERB_APP_SECRET=<generate with: php -r "echo bin2hex(random_bytes(32));">
   REVERB_HOST="your-api-domain.com"     # PUBLIC domain, NOT 127.0.0.1
   REVERB_PORT=6001
   REVERB_SERVER_PORT=6001
   REVERB_SCHEME=http                     # Nginx handles TLS, not Reverb
   ```

3. **Configure frontend `.env.production`:**
   ```env
   VITE_REVERB_APP_KEY="<same as REVERB_APP_KEY>"
   VITE_REVERB_HOST=your-api-domain.com
   VITE_REVERB_PORT=443                   # Through Nginx HTTPS, not 6001
   VITE_REVERB_SCHEME=https               # WSS through Nginx
   ```

4. **Add `broadcasting/auth` to CORS paths** in `config/cors.php`:
   ```php
   'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],
   ```

5. **Configure broadcasting auth** in `bootstrap/app.php`:
   ```php
   ->withBroadcasting(
       __DIR__.'/../routes/channels.php',
       ['middleware' => ['auth:sanctum']],
   )
   ```

6. **Create Supervisor configs** on the server:
   ```bash
   # Reverb server
   sudo tee /etc/supervisor/conf.d/yourproject-reverb.conf << "EOF"
   [program:yourproject-reverb]
   process_name=%(program_name)s
   command=php /srv/www/your-project/current/artisan reverb:start --host=127.0.0.1 --port=6001
   autostart=true
   autorestart=true
   stopasgroup=true
   killasgroup=true
   user=www-data
   redirect_stderr=true
   stdout_logfile=/srv/www/your-project/shared/storage/logs/reverb.log
   stopwaitsecs=3600
   EOF

   # Queue worker
   sudo tee /etc/supervisor/conf.d/yourproject-queue.conf << "EOF"
   [program:yourproject-queue]
   process_name=%(program_name)s_%(process_num)02d
   command=php /srv/www/your-project/current/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
   autostart=true
   autorestart=true
   stopasgroup=true
   killasgroup=true
   user=www-data
   numprocs=1
   redirect_stderr=true
   stdout_logfile=/srv/www/your-project/shared/storage/logs/queue-worker.log
   stopwaitsecs=3600
   EOF
   ```

   **If running multiple projects with Reverb on the same server:** Use different ports for each project (e.g., 6001, 6002, 6003) and update the Nginx proxy and `.env` accordingly.

7. **Add WebSocket proxy to Nginx** (before `location /`):
   ```nginx
   location /app {
       proxy_pass http://127.0.0.1:6001;
       proxy_http_version 1.1;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "upgrade";
       proxy_set_header Host $host;
       proxy_set_header X-Real-IP $remote_addr;
       proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
       proxy_set_header X-Forwarded-Proto $scheme;
       proxy_read_timeout 60s;
       proxy_send_timeout 60s;
   }

   location /apps {
       proxy_pass http://127.0.0.1:6001;
       proxy_http_version 1.1;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "upgrade";
       proxy_set_header Host $host;
       proxy_set_header X-Real-IP $remote_addr;
       proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
       proxy_set_header X-Forwarded-Proto $scheme;
   }
   ```

8. **Restart everything:**
   ```bash
   sudo supervisorctl reread
   sudo supervisorctl update
   sudo nginx -t && sudo systemctl reload nginx
   cd /srv/www/your-project/current && php artisan config:cache
   ```

9. **Add Supervisor restart to your deploy script:**
   ```bash
   if command -v supervisorctl &>/dev/null; then
       sudo supervisorctl restart yourproject-reverb 2>/dev/null
       sudo supervisorctl restart yourproject-queue 2>/dev/null
   fi
   ```

10. **Verify** using the testing steps in [Section 10](#10-verification--testing), substituting your domain and app key.
